<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    /** Supplier directory built from extracted invoices, grouping spelling variants such as "Acme Inc." / "ACME Inc". */
    public function index(Request $request): View
    {
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        $groups = Document::query()
            ->whereNotNull('supplier_name')
            ->whereNotIn('status', [DocumentStatus::Failed->value])
            ->get(['supplier_name', 'supplier_address', 'supplier_tax_id', 'status', 'currency', 'total', 'invoice_date', 'error_count', 'created_at'])
            ->groupBy(fn ($d) => self::key($d->supplier_name))
            ->map(function ($docs) {
                $latest = $docs->sortByDesc('created_at')->first();
                $approved = $docs->where('status', DocumentStatus::Approved);

                return [
                    'name' => $docs->groupBy('supplier_name')->sortByDesc(fn ($g) => $g->count())->keys()->first(),
                    'documents' => $docs->count(),
                    'approved' => $approved->count(),
                    'open_errors' => $docs->filter(fn ($d) => $d->error_count > 0 && ! $d->status->isFinal())->count(),
                    'value' => $approved->groupBy('currency')->map(fn ($g) => $g->sum(fn ($d) => (float) $d->total))->sortDesc(),
                    'last_invoice' => $docs->max('invoice_date'),
                    'tax_id' => $docs->sortByDesc('created_at')->pluck('supplier_tax_id')->filter()->first(),
                    'address' => $docs->sortByDesc('created_at')->pluck('supplier_address')->filter()->first(),
                    'latest' => $latest,
                ];
            })
            ->when($q !== '', fn ($c) => $c->filter(fn ($g) => str_contains(mb_strtolower($g['name']), $q) || str_contains(mb_strtolower((string) $g['tax_id']), $q)))
            ->sortByDesc('documents')
            ->values();

        return view('suppliers.index', ['suppliers' => $groups, 'q' => (string) $request->query('q', '')]);
    }

    public static function key(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/\b(inc|llc|ltd|limited|gmbh|co|corp|corporation|company|plc|llp|pty|ag)\b\.?/u', '', $name) ?? $name;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name);
    }
}
