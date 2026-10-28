@extends('layouts.app')
@section('title', 'Purchase orders')
@section('crumbs')<li class="breadcrumb-item active" aria-current="page">Purchase orders</li>@endsection

@section('content')
@use('App\Support\Format')
@use('App\Models\PurchaseOrder')
<x-page-head title="Purchase orders" subtitle="The PO register invoices are matched against. An invoice that exceeds its PO is blocked from approval.">
    @if($canManage)
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importModal"><i class="bi bi-filetype-csv"></i> Import CSV</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#poModal"><i class="bi bi-plus-lg"></i> New purchase order</button>
    @endif
</x-page-head>

<div class="panel panel-flush">
    <form method="get" class="filter-bar">
        <div class="f grow"><label for="q">Search</label><input id="q" name="q" type="search" class="form-control form-control-sm" value="{{ $q }}" placeholder="PO number or supplier"></div>
        <div class="f"><label for="status">Status</label>
            <select id="status" name="status" class="form-select form-select-sm" data-autosubmit>
                <option value="">All</option><option value="open" @selected($status === 'open')>Open</option><option value="closed" @selected($status === 'closed')>Closed</option>
            </select></div>
        <button class="btn btn-sm btn-primary">Apply</button>
        @if($q || $status)<a href="{{ route('purchase-orders.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>@endif
    </form>
    <div class="table-responsive">
        <table class="table table-clean">
            <thead><tr><th>PO number</th><th>Supplier</th><th>Issued</th><th class="num">PO value</th><th>Invoiced</th><th>Status</th>@if($canManage)<th></th>@endif</tr></thead>
            <tbody>
            @forelse($orders as $po)
                @php
                    $docs = $invoiced->get(PurchaseOrder::normalise($po->po_number), collect());
                    $sum = $docs->sum(fn ($d) => (float) $d->total);
                    $pct = (float) $po->total > 0 ? round($sum / (float) $po->total * 100) : 0;
                @endphp
                <tr>
                    <td class="mono fw-medium text-nowrap"><a href="{{ route('documents.index', ['q' => $po->po_number, 'status' => '']) }}">{{ $po->po_number }}</a></td>
                    <td>{{ $po->supplier_name }}</td>
                    <td class="text-nowrap">{{ $po->issued_on?->format('d M Y') ?? '—' }}</td>
                    <td class="num text-nowrap fw-medium">{{ Format::money($po->total, $po->currency) }}</td>
                    <td style="min-width:150px">
                        <div class="fs-sm num">{{ Format::money($sum, $po->currency) }} <span class="text-muted-2">· {{ $docs->count() }} {{ \Illuminate\Support\Str::plural('invoice', $docs->count()) }}</span></div>
                        <div class="stack mt-1" style="height:5px" aria-label="{{ $pct }}% invoiced"><i class="tone-{{ $pct > 100 ? 'bad' : 'accent' }}" style="width: {{ min(100, $pct) }}%"></i></div>
                    </td>
                    <td><span class="status-pill tone-{{ $po->isOpen() ? 'ok' : 'muted' }}">{{ ucfirst($po->status) }}</span>@if($pct > 100) <span class="status-pill tone-bad">Over PO</span>@endif</td>
                    @if($canManage)
                        <td class="text-end text-nowrap">
                            <form method="post" action="{{ route('purchase-orders.toggle', $po) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $po->isOpen() ? 'Close' : 'Reopen' }}</button></form>
                            @if(auth()->user()->isAdmin())
                                <form method="post" action="{{ route('purchase-orders.destroy', $po) }}" class="d-inline" data-confirm="Invoices that reference {{ $po->po_number }} will show it as not on file." data-confirm-title="Delete this purchase order?" data-confirm-label="Delete" data-confirm-tone="danger">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-secondary text-danger" aria-label="Delete {{ $po->po_number }}"><i class="bi bi-trash"></i></button></form>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="7"><div class="empty-state"><i class="bi bi-journal-x"></i><h3>No purchase orders yet</h3><p class="mb-3">Add the POs you issue so invoices can be matched against them.</p>@if($canManage)<button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#poModal">New purchase order</button>@endif</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())<div class="px-3 py-2 border-top">{{ $orders->links() }}</div>@endif
</div>
@endsection

@push('modals')
@if($canManage)
<div class="modal fade" id="poModal" tabindex="-1" aria-labelledby="poTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="{{ route('purchase-orders.store') }}">
            @csrf
            <div class="modal-header border-0 pb-0"><h2 class="modal-title fs-6" id="poTitle">New purchase order</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body row g-3">
                <div class="col-6"><label class="form-label" for="po_number">PO number</label><input class="form-control mono @error('po_number') is-invalid @enderror" id="po_number" name="po_number" required maxlength="80" value="{{ old('po_number') }}">@error('po_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6"><label class="form-label" for="issued_on">Issued on</label><input type="date" class="form-control" id="issued_on" name="issued_on" value="{{ old('issued_on') }}"></div>
                <div class="col-12"><label class="form-label" for="supplier_name">Supplier</label><input class="form-control" id="supplier_name" name="supplier_name" required maxlength="255" value="{{ old('supplier_name') }}"></div>
                <div class="col-4"><label class="form-label" for="po_currency">Currency</label><select class="form-select" id="po_currency" name="currency" required>@foreach($currencies as $c)<option @selected(old('currency', 'USD') === $c)>{{ $c }}</option>@endforeach</select></div>
                <div class="col-4"><label class="form-label" for="po_total">Value</label><input class="form-control num @error('total') is-invalid @enderror" id="po_total" name="total" inputmode="decimal" required value="{{ old('total') }}">@error('total')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-4"><label class="form-label" for="po_status">Status</label><select class="form-select" id="po_status" name="status"><option value="open">Open</option><option value="closed">Closed</option></select></div>
                <div class="col-12"><label class="form-label" for="po_notes">Notes</label><input class="form-control" id="po_notes" name="notes" maxlength="500" value="{{ old('notes') }}"></div>
            </div>
            <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add purchase order</button></div>
        </form>
    </div>
</div>
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="{{ route('purchase-orders.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-header border-0 pb-0"><h2 class="modal-title fs-6" id="importTitle">Import purchase orders (CSV)</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p class="fs-sm text-muted-2">Columns: <span class="mono">po_number, supplier_name, currency, total, issued_on</span> (the last is optional, format YYYY-MM-DD). Up to 500 rows. Existing PO numbers are skipped.</p>
                <input type="file" name="file" accept=".csv,text/csv" class="form-control @error('file') is-invalid @enderror" required aria-label="CSV file">
                @error('file')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Import</button></div>
        </form>
    </div>
</div>
@endif
@endpush
