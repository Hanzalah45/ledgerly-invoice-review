<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Support\DocumentFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    private const COLUMNS = [
        'document_id', 'source', 'provider', 'model', 'status', 'supplier_name', 'supplier_tax_id', 'invoice_number',
        'po_number', 'invoice_date', 'due_date', 'payment_terms', 'currency', 'subtotal', 'discount', 'shipping', 'tax', 'total',
        'bill_to_name', 'bank_account_masked', 'line_item_count',
        'human_edited', 'average_confidence', 'validation_errors', 'validation_warnings',
        'assigned_to', 'reviewed_by', 'reviewed_at', 'original_filename',
        'department', 'cost_center', 'gl_code', 'approval_steps', 'received_via',
    ];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = DocumentFilters::query($filters);

        return view('exports.index', [
            'filters' => $filters,
            'statuses' => DocumentStatus::options(),
            'count' => (clone $query)->count(),
            'demoCount' => (clone $query)->where('is_demo', true)->count(),
            'preview' => (clone $query)->limit(6)->get(),
            'query' => $request->query(),
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        $documents = $this->query($request)->with('lineItems', 'reviewer', 'assignee', 'department', 'approvals.actor')->get();
        $this->logExport($request, 'csv', $documents->count());

        $filename = 'ledgerly-documents-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($documents) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accented names correctly
            fputcsv($out, self::COLUMNS);
            foreach ($documents as $doc) {
                fputcsv($out, array_map([$this, 'csvCell'], $this->row($doc)));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function json(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $documents = $this->query($request)->with('lineItems', 'reviewer', 'assignee', 'department', 'approvals.actor')->get();
        $this->logExport($request, 'json', $documents->count());

        $payload = [
            'meta' => [
                'exported_at' => now()->toIso8601String(),
                'count' => $documents->count(),
                'filters' => array_filter($filters, fn ($v, $k) => $v !== null && $v !== '' && ! in_array($k, ['sort', 'dir'], true), ARRAY_FILTER_USE_BOTH),
                'contains_demo_data' => $documents->contains(fn ($d) => $d->is_demo),
                'note' => 'Records with source "demo_parser" were produced by a rule-based parser, not an AI model.',
            ],
            'documents' => $documents->map(fn (Document $d) => $this->record($d))->values(),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="ledgerly-documents-'.now()->format('Ymd-His').'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Filtered query, optionally narrowed to ids[] (used by "Export selected" in the document list). */
    private function query(Request $request)
    {
        $query = DocumentFilters::query($this->filters($request));
        $ids = array_filter((array) $request->query('ids'), fn ($v) => is_string($v) && preg_match('/^[0-9a-f-]{36}$/', $v));
        if ($ids) {
            $query->whereIn('uuid', $ids);
        }

        return $query;
    }

    /** Exports default to approved documents so unreviewed data does not leak downstream by accident. */
    private function filters(Request $request): array
    {
        $filters = DocumentFilters::fromRequest($request);
        if (! $request->has('status')) {
            $filters['status'] = DocumentStatus::Approved->value;
        } elseif ($request->query('status') === 'all') {
            $filters['status'] = null;
        }

        return $filters;
    }

    private function row(Document $d): array
    {
        return [
            $d->uuid,
            $d->is_demo ? 'demo_parser' : ($d->provider ? 'live_ai' : 'unprocessed'),
            $d->provider,
            $d->model,
            $d->status->value,
            $d->supplier_name,
            $d->supplier_tax_id,
            $d->invoice_number,
            $d->po_number,
            $d->invoice_date?->toDateString(),
            $d->due_date?->toDateString(),
            $d->payment_terms,
            $d->currency,
            $d->subtotal,
            $d->discount,
            $d->shipping,
            $d->tax,
            $d->total,
            $d->bill_to_name,
            $d->maskedBankAccount(),
            $d->lineItems->count(),
            $d->human_edited ? 'yes' : 'no',
            $d->averageConfidence(),
            $d->errorCount(),
            $d->warningCount(),
            $d->assignee?->name,
            $d->reviewer?->name,
            $d->reviewed_at?->toIso8601String(),
            $d->original_name,
            $d->department?->code,
            $d->department?->cost_center,
            $d->gl_code,
            $this->approvalTrail($d),
            $d->channel,
        ];
    }

    /** "Reviewer: Ana (2026-10-28); Admin: Bo (2026-10-29)" for the approved steps, empty when none. */
    private function approvalTrail(Document $d): string
    {
        return $d->approvals->where('status', 'approved')
            ->map(fn ($a) => sprintf('%s: %s (%s)', $a->label(), $a->actor?->name ?? 'unknown', $a->acted_at?->toDateString()))->implode('; ');
    }

    private function record(Document $d): array
    {
        return [
            'id' => $d->uuid,
            'source' => $d->is_demo ? 'demo_parser' : ($d->provider ? 'live_ai' : 'unprocessed'),
            'provider' => $d->provider,
            'model' => $d->model,
            'status' => $d->status->value,
            'original_filename' => $d->original_name,
            'supplier_name' => $d->supplier_name,
            'supplier_address' => $d->supplier_address,
            'supplier_tax_id' => $d->supplier_tax_id,
            'invoice_number' => $d->invoice_number,
            'po_number' => $d->po_number,
            'invoice_date' => $d->invoice_date?->toDateString(),
            'due_date' => $d->due_date?->toDateString(),
            'payment_terms' => $d->payment_terms,
            'bill_to' => ['name' => $d->bill_to_name, 'address' => $d->bill_to_address],
            'currency' => $d->currency,
            'subtotal' => $d->subtotal !== null ? (float) $d->subtotal : null,
            'discount' => $d->discount !== null ? (float) $d->discount : null,
            'shipping' => $d->shipping !== null ? (float) $d->shipping : null,
            'tax' => $d->tax !== null ? (float) $d->tax : null,
            'total' => $d->total !== null ? (float) $d->total : null,
            'bank_account_masked' => $d->maskedBankAccount(),
            'notes' => $d->invoice_notes,
            'assigned_to' => $d->assignee?->name,
            'department' => $d->department ? ['code' => $d->department->code, 'name' => $d->department->name, 'cost_center' => $d->department->cost_center] : null,
            'gl_code' => $d->gl_code,
            'approvals' => $d->approvals->map(fn ($a) => ['step' => $a->step_order, 'type' => $a->step_type, 'status' => $a->status, 'by' => $a->actor?->name, 'at' => $a->acted_at?->toIso8601String()])->all(),
            'received_via' => $d->channel,
            'received_from' => $d->channel === 'email' ? $d->inbound_from : null,
            'confidence' => $d->confidence,
            'human_edited' => $d->human_edited,
            'validation_issues' => $d->issues(),
            'line_items' => $d->lineItems->map(fn ($i) => [
                'sku' => $i->sku,
                'description' => $i->description,
                'quantity' => $i->quantity !== null ? (float) $i->quantity : null,
                'unit_price' => $i->unit_price !== null ? (float) $i->unit_price : null,
                'tax_rate' => $i->tax_rate !== null ? (float) $i->tax_rate : null,
                'amount' => $i->amount !== null ? (float) $i->amount : null,
            ])->all(),
            'reviewed_by' => $d->reviewer?->name,
            'reviewed_at' => $d->reviewed_at?->toIso8601String(),
        ];
    }

    /** Neutralises spreadsheet formula injection in text pulled from untrusted PDFs. */
    private function csvCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private function logExport(Request $request, string $format, int $count): void
    {
        DocumentEvent::create([
            'document_id' => null,
            'user_id' => $request->user()->getKey(),
            'type' => 'exported',
            'message' => sprintf('Exported %d document(s) as %s.', $count, strtoupper($format)),
            'meta' => ['format' => $format, 'count' => $count],
        ]);
    }
}
