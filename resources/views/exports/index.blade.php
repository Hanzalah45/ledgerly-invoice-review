@extends('layouts.app')
@section('title', 'Exports')
@section('crumbs')<li class="breadcrumb-item active" aria-current="page">Exports</li>@endsection

@section('content')
@use('App\Support\Format')
@php
    $params = array_filter([
        'q' => $filters['q'], 'status' => request()->has('status') ? (request('status') ?: 'all') : null,
        'source' => $filters['source'], 'issues' => $filters['issues'], 'from' => $filters['from'], 'to' => $filters['to'],
    ]);
    $activeStatus = $filters['status'] ?? 'all';
@endphp

<x-page-head title="Exports" subtitle="Download reviewed records as CSV or JSON. Approved documents are exported by default." />

<div class="row g-3">
    <div class="col-lg-4">
        <form method="get" action="{{ route('exports.index') }}" class="panel">
            <div class="panel-head"><h2>What to export</h2></div>
            <div class="panel-body d-grid gap-3">
                <div>
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status" data-autosubmit>
                        <option value="all" @selected($activeStatus === 'all')>All statuses</option>
                        @foreach($statuses as $value => $label)
                            <option value="{{ $value }}" @selected($activeStatus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label" for="source">Source</label>
                    <select class="form-select" id="source" name="source" data-autosubmit>
                        <option value="">Demo and live</option>
                        <option value="demo" @selected($filters['source'] === 'demo')>Demo parser only</option>
                        <option value="live" @selected($filters['source'] === 'live')>Live AI only</option>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col"><label class="form-label" for="from">Invoice date from</label><input type="date" class="form-control" id="from" name="from" value="{{ $filters['from'] }}" data-autosubmit></div>
                    <div class="col"><label class="form-label" for="to">to</label><input type="date" class="form-control" id="to" name="to" value="{{ $filters['to'] }}" data-autosubmit></div>
                </div>
                <div>
                    <label class="form-label" for="q">Search</label>
                    <input type="search" class="form-control" id="q" name="q" value="{{ $filters['q'] }}" placeholder="Supplier or invoice number">
                </div>
                <button class="btn btn-outline-secondary">Update preview</button>
            </div>
        </form>
    </div>

    <div class="col-lg-8">
        <div class="panel mb-3">
            <div class="panel-body d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <div class="fs-xs text-muted-2 text-uppercase fw-semibold" style="letter-spacing:.05em">Records in this export</div>
                    <div class="display-6 fw-semibold num lh-1 mt-1">{{ $count }}</div>
                    @if($demoCount > 0)
                        <div class="fs-sm mt-2 text-warning-emphasis"><i class="bi bi-info-circle"></i> {{ $demoCount }} {{ \Illuminate\Support\Str::plural('record', $demoCount) }} came from the demo rule parser, not an AI model. Exports label them <span class="mono">demo_parser</span>.</div>
                    @endif
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-primary {{ $count === 0 ? 'disabled' : '' }}" href="{{ route('exports.csv', $params) }}" @if($count === 0) aria-disabled="true" tabindex="-1" @endif><i class="bi bi-filetype-csv"></i> Download CSV</a>
                    <a class="btn btn-outline-secondary {{ $count === 0 ? 'disabled' : '' }}" href="{{ route('exports.json', $params) }}" @if($count === 0) aria-disabled="true" tabindex="-1" @endif><i class="bi bi-filetype-json"></i> Download JSON</a>
                </div>
            </div>
        </div>

        <div class="panel panel-flush">
            <div class="panel-head"><div><h2>Preview</h2><div class="sub">First {{ $preview->count() }} of {{ $count }}</div></div></div>
            <div class="table-responsive">
                <table class="table table-clean">
                    <thead><tr><th>Supplier</th><th>Invoice</th><th>Date</th><th class="num">Total</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse($preview as $doc)
                        <tr>
                            <td class="cell-main">{{ $doc->supplier_name ?? '—' }}</td>
                            <td class="mono">{{ $doc->invoice_number ?? '—' }}</td>
                            <td class="text-nowrap">{{ $doc->invoice_date?->format('d M Y') ?? '—' }}</td>
                            <td class="num text-nowrap">{{ Format::money($doc->total, $doc->currency) }}</td>
                            <td><x-status :status="$doc->status" /></td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="empty-state py-4"><i class="bi bi-inbox"></i><h3>Nothing to export</h3><div>No documents match these settings. Approve documents or widen the filters.</div></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <p class="fs-xs text-muted-2 mt-2">Text cells that begin with <span class="mono">= + - @</span> are prefixed with an apostrophe in CSV files to prevent spreadsheet formula injection.</p>
    </div>
</div>
@endsection
