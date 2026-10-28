<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Query-string filters shared by the document list and the exports, so what you filter
 * is exactly what you export.
 */
class DocumentFilters
{
    public const SORTABLE = [
        'created_at', 'supplier_name', 'invoice_number', 'invoice_date', 'total', 'status',
    ];

    /** @return array<string,mixed> */
    public static function fromRequest(Request $request): array
    {
        $sort = $request->query('sort');
        $dir = strtolower((string) $request->query('dir')) === 'asc' ? 'asc' : 'desc';

        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), array_keys(DocumentStatus::options()), true) ? $request->query('status') : null,
            'source' => in_array($request->query('source'), ['demo', 'live'], true) ? $request->query('source') : null,
            'issues' => in_array($request->query('issues'), ['errors', 'warnings', 'clean'], true) ? $request->query('issues') : null,
            'assigned' => (function () use ($request) {
                $v = $request->query('assigned');

                return in_array($v, ['me', 'unassigned'], true) || (ctype_digit((string) $v) && $v !== '') ? (string) $v : null;
            })(),
            'from' => self::date($request->query('from')),
            'to' => self::date($request->query('to')),
            'sort' => in_array($sort, self::SORTABLE, true) ? $sort : 'created_at',
            'dir' => $dir,
        ];
    }

    /** @param array<string,mixed> $filters */
    public static function apply(Builder $query, array $filters): Builder
    {
        $query->search($filters['q'] ?? null);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['source'] ?? null) === 'demo') {
            $query->where('is_demo', true);
        } elseif (($filters['source'] ?? null) === 'live') {
            $query->where('is_demo', false)->whereNotNull('provider');
        }
        match ($filters['issues'] ?? null) {
            'errors' => $query->where('error_count', '>', 0),
            'warnings' => $query->where('warning_count', '>', 0)->where('error_count', 0),
            'clean' => $query->whereIn('status', [DocumentStatus::Ready->value, DocumentStatus::Approved->value]),
            default => null,
        };
        match (true) {
            ($filters['assigned'] ?? null) === 'me' => $query->where('assigned_to', auth()->id()),
            ($filters['assigned'] ?? null) === 'unassigned' => $query->whereNull('assigned_to'),
            filled($filters['assigned'] ?? null) => $query->where('assigned_to', (int) $filters['assigned']),
            default => null,
        };
        if (! empty($filters['from'])) {
            $query->whereDate('invoice_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('invoice_date', '<=', $filters['to']);
        }

        return $query->orderBy($filters['sort'] ?? 'created_at', $filters['dir'] ?? 'desc')->orderByDesc('id');
    }

    /** @param array<string,mixed> $filters */
    public static function query(array $filters): Builder
    {
        return self::apply(Document::query(), $filters);
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
