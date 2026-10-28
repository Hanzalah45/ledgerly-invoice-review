<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardMetrics
{
    /** @return array<string,mixed> */
    public function build(): array
    {
        $since30 = now()->subDays(30);

        $statusCounts = Document::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $count = fn (DocumentStatus ...$statuses) => (int) collect($statuses)->sum(fn ($s) => $statusCounts[$s->value] ?? 0);

        $total = (int) $statusCounts->sum();
        $withErrors = Document::withErrors()->count();

        $approvedByCurrency = Document::query()
            ->where('status', DocumentStatus::Approved->value)
            ->whereNotNull('currency')
            ->selectRaw('currency, COUNT(*) as documents, SUM(total) as value')
            ->groupBy('currency')
            ->orderByDesc('value')
            ->get();

        $avgConfidence = Document::query()
            ->whereNotNull('confidence')
            ->whereNotIn('status', [DocumentStatus::Failed->value])
            ->get(['confidence', 'human_edited'])
            ->map(fn ($d) => collect($d->confidence)->filter(fn ($v) => is_numeric($v))->avg())
            ->filter()
            ->avg();

        return [
            'total' => $total,
            'processed30' => Document::where('created_at', '>=', $since30)->count(),
            'awaiting' => $count(DocumentStatus::NeedsReview, DocumentStatus::Ready),
            'approved' => $count(DocumentStatus::Approved),
            'rejected' => $count(DocumentStatus::Rejected),
            'failed' => $count(DocumentStatus::Failed),
            'inFlight' => $count(DocumentStatus::Uploaded, DocumentStatus::Processing),
            'errorRate' => $total > 0 ? round($withErrors / $total * 100) : 0,
            'withErrors' => $withErrors,
            'avgConfidence' => $avgConfidence !== null ? (int) round($avgConfidence * 100) : null,
            'approvedByCurrency' => $approvedByCurrency,
            'statusBreakdown' => $this->statusBreakdown($statusCounts, $total),
            'perDay' => $this->perDay(14),
            'attention' => Document::withErrors()->whereNotIn('status', [DocumentStatus::Approved->value, DocumentStatus::Rejected->value])->latest()->limit(6)->get(),
            'recent' => Document::latest()->limit(6)->get(),
            'topSuppliers' => $this->topSuppliers(),
        ];
    }

    /** @return Collection<int,array{label:string,value:string,count:int,percent:float,tone:string}> */
    private function statusBreakdown(Collection $counts, int $total): Collection
    {
        return collect(DocumentStatus::cases())
            ->map(fn (DocumentStatus $s) => [
                'label' => $s->label(),
                'value' => $s->value,
                'count' => (int) ($counts[$s->value] ?? 0),
                'percent' => $total > 0 ? round(($counts[$s->value] ?? 0) / $total * 100, 1) : 0,
                'tone' => $s->tone(),
            ])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values();
    }

    /** @return array<int,array{date:Carbon,label:string,count:int}> */
    private function perDay(int $days): array
    {
        $start = now()->startOfDay()->subDays($days - 1);
        $counts = Document::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->groupBy(fn ($d) => $d->created_at->format('Y-m-d'))
            ->map->count();

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $series[] = ['date' => $day, 'label' => $day->format('M j'), 'count' => (int) ($counts[$day->format('Y-m-d')] ?? 0)];
        }

        return $series;
    }

    private function topSuppliers(): Collection
    {
        return Document::query()
            ->whereNotNull('supplier_name')
            ->whereNotIn('status', [DocumentStatus::Rejected->value, DocumentStatus::Failed->value])
            ->selectRaw('supplier_name, COUNT(*) as documents, MAX(currency) as currency, SUM(total) as value')
            ->groupBy('supplier_name')
            ->orderByDesc('documents')
            ->orderByDesc('value')
            ->limit(5)
            ->get();
    }
}
