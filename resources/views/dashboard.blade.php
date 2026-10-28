@extends('layouts.app')
@section('title', 'Dashboard')
@section('crumbs')<li class="breadcrumb-item active" aria-current="page">Dashboard</li>@endsection

@section('content')
@use('App\Support\Format')
<x-page-head title="Dashboard" subtitle="Processing activity, the review queue and data quality across your supplier invoices.">
    @can('create', \App\Models\Document::class)
        <a href="{{ route('documents.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Upload documents</a>
    @endcan
</x-page-head>

@unless($liveProvider)
    <div class="demo-banner" role="note">
        <i class="bi bi-info-circle"></i>
        <div><strong>Demo mode.</strong> Extraction runs on a local rule-based parser over each PDF's text, not an AI model, and every record is labelled that way. Set <span class="mono">DOCAI_PROVIDER</span> to <span class="mono">openai</span> or <span class="mono">anthropic</span> with an API key in <span class="mono">.env</span> for live extraction.</div>
    </div>
@endunless

<div class="stat-strip mb-3">
    <div class="cell">
        <div class="label">Uploaded · 30 days</div>
        <div class="value">{{ number_format($m['processed30']) }}</div>
        <div class="hint">{{ number_format($m['total']) }} documents in total</div>
    </div>
    <div class="cell {{ $m['awaiting'] > 0 ? 'alert' : '' }}">
        <div class="label">Awaiting review</div>
        <div class="value">{{ number_format($m['awaiting']) }}</div>
        <div class="hint"><a href="{{ route('documents.index', ['issues' => 'errors']) }}">{{ $m['withErrors'] }} blocked by errors</a></div>
    </div>
    <div class="cell">
        <div class="label">Approved</div>
        <div class="value">{{ number_format($m['approved']) }}</div>
        <div class="hint">{{ $m['rejected'] }} rejected · {{ $m['failed'] }} failed</div>
    </div>
    <div class="cell">
        <div class="label">Documents with errors</div>
        <div class="value">{{ $m['errorRate'] }}<span style="font-size:1.1rem;color:var(--ink-3);font-weight:500">%</span></div>
        <div class="hint">@if($m['avgConfidence'] !== null)Average field confidence {{ $m['avgConfidence'] }}%@else No confidence data yet @endif</div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="panel h-100">
            <div class="panel-head"><div><h2>Documents uploaded</h2><div class="sub">Last 14 days</div></div></div>
            <div class="panel-body">
                @php($max = max(1, collect($m['perDay'])->max('count')))
                <div class="chart" role="img" aria-label="Documents uploaded per day over the last 14 days">
                    <div class="grid"><i></i><i></i><i></i><i></i></div>
                    <div class="cols">
                        @foreach($m['perDay'] as $day)
                            <div class="col {{ $day['count'] === 0 ? 'zero' : '' }} {{ $loop->last ? 'today' : '' }}" title="{{ $day['label'] }}: {{ $day['count'] }}">
                                <div class="bar" style="height: calc((100% - 22px) * {{ $day['count'] === 0 ? 0 : max(0.06, round($day['count'] / $max, 3)) }})"></div>
                                @if($day['count'] > 0)<div class="val" style="bottom: calc(22px + (100% - 22px) * {{ round($day['count'] / $max, 3) }} + 3px)">{{ $day['count'] }}</div>@endif
                                <div class="lbl">{{ $loop->iteration % 2 === 1 ? $day['date']->format('j M') : '' }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-baseline mt-4 mb-2">
                    <div class="eyebrow">Status breakdown</div><span class="fs-xs text-muted-2">{{ $m['total'] }} documents</span>
                </div>
                <div class="stack" role="img" aria-label="Share of documents by status">
                    @foreach($m['statusBreakdown'] as $row)<i class="tone-{{ $row['tone'] }}" style="width: {{ $row['percent'] }}%" title="{{ $row['label'] }}: {{ $row['count'] }}"></i>@endforeach
                </div>
                <div class="row row-cols-2 row-cols-md-3 gx-4 mt-2">
                    @foreach($m['statusBreakdown'] as $row)
                        <div class="col"><a class="legend-row" href="{{ route('documents.index', ['status' => $row['value']]) }}"><span class="sw tone-{{ $row['tone'] }}"></span>{{ $row['label'] }}<span class="n">{{ $row['count'] }}</span></a></div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="panel mb-3">
            <div class="panel-head"><div><h2>Approved value</h2><div class="sub">By invoice currency</div></div></div>
            @forelse($m['approvedByCurrency'] as $row)
                <div class="d-flex justify-content-between align-items-baseline px-3 py-2 {{ ! $loop->last ? 'border-bottom' : '' }}" style="border-color:var(--line-2)!important">
                    <div><span class="fw-semibold">{{ $row->currency }}</span> <span class="fs-xs text-muted-2 ms-1">{{ $row->documents }} {{ \Illuminate\Support\Str::plural('invoice', $row->documents) }}</span></div>
                    <div class="num fw-semibold" style="font-size:1.05rem;letter-spacing:-0.01em">{{ Format::money($row->value, $row->currency) }}</div>
                </div>
            @empty
                <div class="empty-state py-4"><div>No approved invoices yet.</div></div>
            @endforelse
        </div>
        <div class="panel">
            <div class="panel-head"><div><h2>Top suppliers</h2><div class="sub">By number of invoices</div></div></div>
            @forelse($m['topSuppliers'] as $row)
                <a href="{{ route('documents.index', ['q' => $row->supplier_name]) }}" class="d-flex justify-content-between align-items-center px-3 py-2 text-body {{ ! $loop->last ? 'border-bottom' : '' }}" style="border-color:var(--line-2)!important">
                    <span class="text-truncate pe-2">{{ $row->supplier_name }}</span>
                    <span class="fs-xs text-muted-2 text-nowrap">{{ $row->documents }} · <span class="num">{{ Format::money($row->value, $row->currency) }}</span></span>
                </a>
            @empty
                <div class="empty-state py-4"><div>Suppliers appear after the first extraction.</div></div>
            @endforelse
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="panel panel-flush h-100">
            <div class="panel-head"><div><h2>Needs attention</h2><div class="sub">Validation errors that block approval</div></div><a href="{{ route('documents.index', ['issues' => 'errors']) }}" class="fs-sm">View all</a></div>
            @forelse($m['attention'] as $doc)
                <a href="{{ route('documents.show', $doc) }}" class="d-block px-3 py-2 text-body border-bottom" style="border-color:var(--line-2)!important">
                    <div class="d-flex justify-content-between gap-2">
                        <span class="fw-medium text-truncate">{{ $doc->supplier_name ?? $doc->original_name }}</span>
                        <x-status :status="$doc->status" />
                    </div>
                    <div class="fs-xs text-muted-2 text-truncate">{{ collect($doc->issues())->firstWhere('severity', 'error')['message'] ?? '' }}</div>
                </a>
            @empty
                <div class="empty-state py-5"><h3>All clear</h3><div>No documents are blocked by validation errors.</div></div>
            @endforelse
        </div>
    </div>
    <div class="col-lg-6">
        <div class="panel panel-flush h-100">
            <div class="panel-head"><div><h2>Recent uploads</h2><div class="sub">Latest documents in the workspace</div></div><a href="{{ route('documents.index') }}" class="fs-sm">All documents</a></div>
            <div class="table-responsive">
                <table class="table table-clean">
                    <tbody>
                    @forelse($m['recent'] as $doc)
                        <tr class="row-link">
                            <td><a href="{{ route('documents.show', $doc) }}" class="cell-main d-block text-truncate" style="max-width:230px">{{ $doc->supplier_name ?? $doc->original_name }}</a>
                                <div class="cell-sub">{{ $doc->invoice_number ?? 'No invoice number' }} · {{ $doc->created_at->diffForHumans() }}</div></td>
                            <td class="num text-nowrap">{{ Format::money($doc->total, $doc->currency) }}</td>
                            <td class="text-end"><x-status :status="$doc->status" /></td>
                        </tr>
                    @empty
                        <tr><td><div class="empty-state py-4"><div>No documents yet.</div></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
