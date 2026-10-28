@extends('layouts.app')
@section('title', 'Documents')
@section('crumbs')<li class="breadcrumb-item active" aria-current="page">Documents</li>@endsection

@section('content')
@use('App\Support\Format')
@php
    $hasFilters = collect($filters)->only(['q', 'source', 'issues', 'from', 'to'])->filter()->isNotEmpty() || $filters['status'];
    $sortLink = function (string $column) use ($filters) {
        $dir = $filters['sort'] === $column && $filters['dir'] === 'asc' ? 'desc' : 'asc';
        return route('documents.index', array_merge(request()->except(['page', 'sort', 'dir']), ['sort' => $column, 'dir' => $dir]));
    };
    $sortIcon = fn (string $column) => $filters['sort'] === $column ? ($filters['dir'] === 'asc' ? 'bi-arrow-up-short' : 'bi-arrow-down-short') : '';
    $totalAll = $statusCounts->sum();
@endphp

<x-page-head title="Documents" subtitle="Every uploaded invoice with its extraction and review status.">
    <a href="{{ route('exports.index', request()->only(['q', 'status', 'source', 'issues', 'from', 'to'])) }}" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-down"></i> Export</a>
    @can('create', \App\Models\Document::class)
        <a href="{{ route('documents.create') }}" class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Upload</a>
    @endcan
</x-page-head>

<div class="panel panel-flush">
    <div class="status-tabs">
        <a href="{{ route('documents.index', request()->except(['status', 'page'])) }}" class="{{ ! $filters['status'] ? 'active' : '' }}">All<span>{{ $totalAll }}</span></a>
        @foreach($statuses as $value => $label)
            @if(($statusCounts[$value] ?? 0) > 0)
                <a href="{{ route('documents.index', array_merge(request()->except(['status', 'page']), ['status' => $value])) }}" class="{{ $filters['status'] === $value ? 'active' : '' }}">{{ $label }}<span>{{ $statusCounts[$value] }}</span></a>
            @endif
        @endforeach
    </div>

    <form method="get" action="{{ route('documents.index') }}" class="filter-bar">
        @if($filters['status'])<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
        <div class="f grow">
            <label for="q">Search</label>
            <input type="search" id="q" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="Supplier, invoice number or file name">
        </div>
        <div class="f">
            <label for="source">Source</label>
            <select id="source" name="source" class="form-select form-select-sm" data-autosubmit>
                <option value="">All sources</option>
                <option value="demo" @selected($filters['source'] === 'demo')>Demo parser</option>
                <option value="live" @selected($filters['source'] === 'live')>Live AI</option>
            </select>
        </div>
        <div class="f">
            <label for="issues">Validation</label>
            <select id="issues" name="issues" class="form-select form-select-sm" data-autosubmit>
                <option value="">Any</option>
                <option value="errors" @selected($filters['issues'] === 'errors')>Has errors</option>
                <option value="warnings" @selected($filters['issues'] === 'warnings')>Warnings only</option>
                <option value="clean" @selected($filters['issues'] === 'clean')>Clean</option>
            </select>
        </div>
        <div class="f">
            <label for="assigned">Assigned to</label>
            <select id="assigned" name="assigned" class="form-select form-select-sm" data-autosubmit>
                <option value="">Anyone</option>
                <option value="me" @selected($filters['assigned'] === 'me')>Me</option>
                <option value="unassigned" @selected($filters['assigned'] === 'unassigned')>Unassigned</option>
                @foreach($assignees as $person)<option value="{{ $person->id }}" @selected($filters['assigned'] === (string) $person->id)>{{ $person->name }}</option>@endforeach
            </select>
        </div>
        <div class="f">
            <label for="from">Invoice date from</label>
            <input type="date" id="from" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm" data-autosubmit>
        </div>
        <div class="f">
            <label for="to">to</label>
            <input type="date" id="to" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm" data-autosubmit>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-primary">Apply</button>
            @if($hasFilters)<a href="{{ route('documents.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>@endif
        </div>
    </form>

    @if($canBulk)
        <div id="bulkBar" class="bulk-bar d-none" role="region" aria-label="Bulk actions">
            <strong id="bulkCount" class="me-1">0 selected</strong>
            <form method="post" action="{{ route('documents.bulk') }}" data-confirm="Approves every selected document that has no validation errors. Others are skipped and listed." data-confirm-title="Approve selected?" data-confirm-label="Approve">
                @csrf <input type="hidden" name="action" value="approve"><span data-bulk-ids></span>
                <button class="btn btn-sm btn-primary"><i class="bi bi-check2-circle"></i> Approve</button>
            </form>
            <form method="post" action="{{ route('documents.bulk') }}" class="d-flex gap-1">
                @csrf <input type="hidden" name="action" value="assign"><span data-bulk-ids></span>
                <select name="assigned_to" class="form-select form-select-sm" style="width:auto" aria-label="Assign selected to">
                    <option value="">Unassign</option>
                    @foreach($assignees as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                </select>
                <button class="btn btn-sm btn-outline-secondary">Assign</button>
            </form>
            <form method="post" action="{{ route('documents.bulk') }}" class="d-flex gap-1">
                @csrf <input type="hidden" name="action" value="reject"><span data-bulk-ids></span>
                <input name="reason" class="form-control form-control-sm" style="width:170px" placeholder="Reason to reject" minlength="3" maxlength="200" required aria-label="Rejection reason">
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i> Reject</button>
            </form>
            <form method="post" action="{{ route('documents.bulk') }}" data-confirm="Extraction runs again for each selected document. Manual edits are replaced." data-confirm-title="Reprocess selected?" data-confirm-label="Reprocess">
                @csrf <input type="hidden" name="action" value="reprocess"><span data-bulk-ids></span>
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Reprocess</button>
            </form>
            <a id="bulkExport" class="btn btn-sm btn-outline-secondary" data-base="{{ route('exports.csv') }}" href="{{ route('exports.csv') }}"><i class="bi bi-filetype-csv"></i> Export selected</a>
            @if($isAdmin)
                <form method="post" action="{{ route('documents.bulk') }}" data-confirm="The selected PDFs, extracted data and history will be permanently removed." data-confirm-title="Delete selected?" data-confirm-label="Delete" data-confirm-tone="danger">
                    @csrf <input type="hidden" name="action" value="delete"><span data-bulk-ids></span>
                    <button class="btn btn-sm btn-outline-secondary text-danger"><i class="bi bi-trash"></i> Delete</button>
                </form>
            @endif
            <button type="button" id="bulkClear" class="btn btn-sm btn-link ms-auto">Clear selection</button>
        </div>
    @endif

    <div class="table-responsive">
        <table class="table table-clean table-hover" id="documentsTable">
            <thead>
            <tr>
                @if($canBulk)<th style="width:34px"><input type="checkbox" id="selectAll" class="form-check-input" aria-label="Select all documents on this page"></th>@endif
                <th><a href="{{ $sortLink('supplier_name') }}">Supplier <i class="bi {{ $sortIcon('supplier_name') }}"></i></a></th>
                <th><a href="{{ $sortLink('invoice_number') }}">Invoice <i class="bi {{ $sortIcon('invoice_number') }}"></i></a></th>
                <th><a href="{{ $sortLink('invoice_date') }}">Date <i class="bi {{ $sortIcon('invoice_date') }}"></i></a></th>
                <th class="num"><a href="{{ $sortLink('total') }}">Total <i class="bi {{ $sortIcon('total') }}"></i></a></th>
                <th><a href="{{ $sortLink('status') }}">Status <i class="bi {{ $sortIcon('status') }}"></i></a></th>
                <th>Checks</th>
                <th>Source</th>
                <th>Assigned</th>
                <th><a href="{{ $sortLink('created_at') }}">Uploaded <i class="bi {{ $sortIcon('created_at') }}"></i></a></th>
            </tr>
            </thead>
            <tbody>
            @forelse($documents as $doc)
                <tr class="row-link">
                    @if($canBulk)<td><input type="checkbox" class="form-check-input row-check" value="{{ $doc->uuid }}" aria-label="Select {{ $doc->invoice_number ?: $doc->original_name }}"></td>@endif
                    <td style="max-width: 280px">
                        <a href="{{ route('documents.show', $doc) }}" class="cell-main d-block text-truncate">{{ $doc->supplier_name ?? 'Unknown supplier' }}</a>
                        <div class="cell-sub text-truncate"><i class="bi bi-file-earmark-pdf"></i> {{ $doc->original_name }}</div>
                    </td>
                    <td class="mono text-nowrap">{{ $doc->invoice_number ?? '—' }}</td>
                    <td class="text-nowrap">{{ $doc->invoice_date?->format('d M Y') ?? '—' }}</td>
                    <td class="num text-nowrap fw-medium">{{ Format::money($doc->total, $doc->currency) }}</td>
                    <td class="text-nowrap"><x-status :status="$doc->status" /></td>
                    <td class="text-nowrap">
                        @if($doc->errorCount() > 0)
                            <span class="text-danger fs-sm" title="{{ $doc->errorCount() }} validation error(s)"><i class="bi bi-x-circle-fill"></i> {{ $doc->errorCount() }}</span>
                        @endif
                        @if($doc->warningCount() > 0)
                            <span class="text-warning fs-sm ms-1" title="{{ $doc->warningCount() }} warning(s)"><i class="bi bi-exclamation-triangle-fill"></i> {{ $doc->warningCount() }}</span>
                        @endif
                        @if($doc->errorCount() + $doc->warningCount() === 0 && $doc->processed_at && $doc->status !== \App\Enums\DocumentStatus::Failed)
                            <span class="text-success fs-sm"><i class="bi bi-check-circle-fill"></i></span>
                        @endif
                    </td>
                    <td><x-source-tag :document="$doc" /> @if($doc->human_edited)<span class="tag edited">Edited</span>@endif</td>
                    <td class="text-nowrap fs-sm">@if($doc->assignee)<span class="avatar avatar-sm" title="{{ $doc->assignee->name }}">{{ collect(explode(' ', $doc->assignee->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>@else<span class="text-muted-2">—</span>@endif</td>
                    <td class="text-nowrap text-muted-2 fs-sm" title="{{ $doc->created_at->toDayDateTimeString() }}">{{ $doc->created_at->diffForHumans() }}</td>
                </tr>
            @empty
                <tr><td colspan="10">
                    <div class="empty-state">
                        <i class="bi bi-file-earmark-x"></i>
                        <h3>{{ $hasFilters ? 'No documents match these filters' : 'No documents yet' }}</h3>
                        <p class="mb-3">{{ $hasFilters ? 'Try a different search term or clear the filters.' : 'Upload your first invoice PDF to see extraction and validation in action.' }}</p>
                        @if($hasFilters)
                            <a href="{{ route('documents.index') }}" class="btn btn-outline-secondary btn-sm">Clear filters</a>
                        @else
                            <a href="{{ route('documents.create') }}" class="btn btn-primary btn-sm">Upload documents</a>
                        @endif
                    </div>
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($documents->hasPages() || $documents->total() > 0)
        <div class="px-3 py-2 border-top">{{ $documents->links() }}</div>
    @endif
</div>
@endsection
