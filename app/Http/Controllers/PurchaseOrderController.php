<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\PurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): View
    {
        $orders = PurchaseOrder::query()->search($request->query('q'))
            ->when(in_array($request->query('status'), ['open', 'closed'], true), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate(12)->withQueryString();

        // Invoiced value per PO (invoices that reference the number, excluding rejected/failed).
        $invoiced = Document::query()
            ->whereNotNull('po_number')
            ->whereNotIn('status', [DocumentStatus::Rejected->value, DocumentStatus::Failed->value])
            ->get(['po_number', 'total', 'currency'])
            ->groupBy(fn ($d) => PurchaseOrder::normalise($d->po_number));

        return view('purchase-orders.index', [
            'orders' => $orders,
            'invoiced' => $invoiced,
            'q' => (string) $request->query('q', ''),
            'status' => $request->query('status'),
            'currencies' => config('docai.currencies'),
            'canManage' => $request->user()->canReview(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canReview(), 403);
        if (is_string($request->input('total'))) {
            $request->merge(['total' => str_replace([',', ' '], '', trim($request->input('total')))]);
        }

        $data = $request->validate([
            'po_number' => ['required', 'string', 'max:80', function ($attribute, $value, $fail) {
                if (PurchaseOrder::findByNumber($value)) {
                    $fail('A purchase order with this number already exists.');
                }
            }],
            'supplier_name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/'],
            'total' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
            'issued_on' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['open', 'closed'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        PurchaseOrder::create($data + ['created_by' => $request->user()->id]);
        $this->reevaluate($data['po_number']);

        return back()->with('success', "Purchase order {$data['po_number']} added. Matching invoices were re-checked.");
    }

    public function toggle(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($request->user()->canReview(), 403);
        $purchaseOrder->update(['status' => $purchaseOrder->isOpen() ? 'closed' : 'open']);
        $this->reevaluate($purchaseOrder->po_number);

        return back()->with('success', "{$purchaseOrder->po_number} is now {$purchaseOrder->status}.");
    }

    public function destroy(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $number = $purchaseOrder->po_number;
        $purchaseOrder->delete();
        $this->reevaluate($number);

        return back()->with('success', "Purchase order {$number} deleted.");
    }

    /** CSV columns: po_number, supplier_name, currency, total, issued_on (optional). */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canReview(), 403);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:512']], ['file.mimes' => 'Upload a CSV file.']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($h) => strtolower(trim(ltrim((string) $h, "\xEF\xBB\xBF"))), fgetcsv($handle) ?: []);
        foreach (['po_number', 'supplier_name', 'currency', 'total'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);

                return back()->withErrors(['file' => "The CSV needs a '{$required}' column. Expected: po_number, supplier_name, currency, total, issued_on."]);
            }
        }

        $created = 0;
        $skipped = [];
        $line = 1;
        while (($row = fgetcsv($handle)) !== false && $line < 501) {
            $line++;
            if (count($row) !== count($header)) {
                $skipped[] = "line {$line}: wrong number of columns";

                continue;
            }
            $r = array_combine($header, array_map('trim', $row));
            $r['total'] = str_replace([',', ' '], '', $r['total']);
            $v = validator($r + ['issued_on' => null], [
                'po_number' => ['required', 'max:80'], 'supplier_name' => ['required', 'max:255'],
                'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'total' => ['required', 'numeric', 'gt:0'],
                'issued_on' => ['nullable', 'date_format:Y-m-d'],
            ]);
            if ($v->fails()) {
                $skipped[] = "line {$line}: ".$v->errors()->first();

                continue;
            }
            if (PurchaseOrder::findByNumber($r['po_number'])) {
                $skipped[] = "line {$line}: {$r['po_number']} already exists";

                continue;
            }
            PurchaseOrder::create([
                'po_number' => $r['po_number'], 'supplier_name' => $r['supplier_name'], 'currency' => $r['currency'],
                'total' => $r['total'], 'issued_on' => $r['issued_on'] ?: null, 'status' => 'open', 'created_by' => $request->user()->id,
            ]);
            $created++;
        }
        fclose($handle);

        $message = "Imported {$created} purchase order(s).";
        if ($skipped) {
            $message .= ' Skipped '.count($skipped).': '.implode('; ', array_slice($skipped, 0, 4)).(count($skipped) > 4 ? '…' : '');
        }
        if ($created > 0) {
            Document::query()->whereNotNull('po_number')->get()->each(fn ($d) => $this->reevaluate($d->po_number));
        }

        return back()->with($skipped ? 'warning' : 'success', $message);
    }

    /** Re-validate open invoices that reference this PO so their PO checks reflect the change. */
    private function reevaluate(string $poNumber): void
    {
        $processor = app(\App\Services\DocumentProcessor::class);
        Document::query()
            ->whereNotNull('po_number')
            ->whereNotIn('status', [DocumentStatus::Approved->value, DocumentStatus::Rejected->value, DocumentStatus::Failed->value, DocumentStatus::Processing->value, DocumentStatus::Uploaded->value])
            ->get()
            ->filter(fn ($d) => PurchaseOrder::normalise($d->po_number) === PurchaseOrder::normalise($poNumber))
            ->each(fn ($d) => $processor->revalidate($d));
    }
}
