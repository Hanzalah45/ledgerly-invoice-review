@extends('layouts.app')
@section('title', 'Suppliers')
@section('crumbs')<li class="breadcrumb-item active" aria-current="page">Suppliers</li>@endsection

@section('content')
@use('App\Support\Format')
<x-page-head title="Suppliers" subtitle="Everyone you have received invoices from. Spelling variants of the same name are grouped." />

<div class="panel panel-flush">
    <form method="get" class="filter-bar">
        <div class="f grow"><label for="q">Search</label><input id="q" name="q" type="search" class="form-control form-control-sm" value="{{ $q }}" placeholder="Supplier name or tax ID"></div>
        <button class="btn btn-sm btn-primary">Apply</button>
        @if($q)<a href="{{ route('suppliers.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>@endif
    </form>
    <div class="table-responsive">
        <table class="table table-clean table-hover">
            <thead><tr><th>Supplier</th><th>Tax ID</th><th class="num">Invoices</th><th class="num">Approved</th><th>Approved value</th><th>Open errors</th><th>Last invoice</th></tr></thead>
            <tbody>
            @forelse($suppliers as $s)
                <tr class="row-link">
                    <td style="max-width:300px"><a class="cell-main d-block text-truncate" href="{{ route('documents.index', ['q' => $s['name']]) }}">{{ $s['name'] }}</a>
                        @if($s['address'])<div class="cell-sub text-truncate">{{ str_replace("\n", ', ', $s['address']) }}</div>@endif</td>
                    <td class="mono text-nowrap">{{ $s['tax_id'] ?: '—' }}</td>
                    <td class="num">{{ $s['documents'] }}</td>
                    <td class="num">{{ $s['approved'] }}</td>
                    <td class="text-nowrap num">@forelse($s['value'] as $cur => $amount)<div>{{ Format::money($amount, $cur) }}</div>@empty<span class="text-muted-2">—</span>@endforelse</td>
                    <td>@if($s['open_errors'] > 0)<span class="status-pill tone-bad">{{ $s['open_errors'] }}</span>@else<span class="text-muted-2">—</span>@endif</td>
                    <td class="text-nowrap">{{ $s['last_invoice'] ? \Illuminate\Support\Carbon::parse($s['last_invoice'])->format('d M Y') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><i class="bi bi-buildings"></i><h3>{{ $q ? 'No suppliers match' : 'No suppliers yet' }}</h3><p class="mb-0">Suppliers appear automatically after the first invoice is extracted.</p></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
