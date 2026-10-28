@extends('layouts.app')
@section('title', 'Activity')
@section('crumbs')<li class="breadcrumb-item active" aria-current="page">Activity</li>@endsection

@section('content')
<x-page-head title="Activity" subtitle="A complete audit trail of processing, edits and decisions across the workspace." />

<div class="panel panel-flush">
    <form method="get" class="filter-bar">
        <div class="f">
            <label for="type">Event type</label>
            <select id="type" name="type" class="form-select form-select-sm" data-autosubmit>
                <option value="">All events</option>
                @foreach($types as $t)
                    <option value="{{ $t }}" @selected($type === $t)>{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                @endforeach
            </select>
        </div>
        @if($type)<a href="{{ route('activity') }}" class="btn btn-sm btn-outline-secondary">Clear</a>@endif
    </form>

    <div class="table-responsive">
        <table class="table table-clean">
            <thead><tr><th style="width:170px">When</th><th>Event</th><th>Document</th><th>By</th></tr></thead>
            <tbody>
            @forelse($events as $event)
                @php([$icon, $tone] = $event->icon())
                <tr>
                    <td class="text-nowrap text-muted-2 fs-sm" title="{{ $event->created_at->toDayDateTimeString() }}">{{ $event->created_at->format('d M, H:i') }}</td>
                    <td><span class="d-inline-flex align-items-center gap-2"><span class="status-pill tone-{{ $tone }}" style="padding:.1rem .5rem"><i class="bi {{ $icon }}"></i>{{ ucfirst(str_replace('_', ' ', $event->type)) }}</span></span>
                        <div class="cell-sub mt-1">{{ $event->message }}</div></td>
                    <td>
                        @if($event->document)
                            <a href="{{ route('documents.show', $event->document) }}" class="cell-main">{{ $event->document->supplier_name ?? $event->document->original_name }}</a>
                            <div class="cell-sub mono">{{ $event->document->invoice_number ?? $event->document->original_name }}</div>
                        @else
                            <span class="text-muted-2">—</span>
                        @endif
                    </td>
                    <td class="text-nowrap">{{ $event->user?->name ?? 'System' }}</td>
                </tr>
            @empty
                <tr><td colspan="4"><div class="empty-state"><i class="bi bi-clock-history"></i><h3>No activity yet</h3><div>Events appear here as documents are uploaded and reviewed.</div></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($events->hasPages())
        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
            <div class="fs-sm text-muted-2">Page {{ $events->currentPage() }} of {{ $events->lastPage() }}</div>{{ $events->links() }}
        </div>
    @endif
</div>
@endsection
