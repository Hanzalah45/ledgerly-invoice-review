<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Approval record {{ $document->invoice_number }}</title>
<style>
    @page { margin: 42px 48px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #15201d; line-height: 1.5; }
    h1 { font-size: 17px; margin: 0 0 2px 0; }
    h2 { font-size: 10px; text-transform: uppercase; letter-spacing: 0.8px; color: #6a736f; margin: 20px 0 6px 0; border-bottom: 1px solid #e4dfd3; padding-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 4px 6px; vertical-align: top; }
    th { text-align: left; font-size: 8.5px; color: #6a736f; text-transform: uppercase; border-bottom: 1px solid #cfc8b8; }
    .kv td:first-child { width: 28%; color: #6a736f; }
    .num { text-align: right; }
    .muted { color: #6a736f; }
    .stamp { display: inline-block; border: 1.5px solid #2e7d4f; color: #2e7d4f; padding: 3px 10px; font-weight: bold; letter-spacing: 1px; font-size: 11px; }
    .warn { background: #fbf3dc; border: 1px solid #ecd9a8; color: #6b4a12; padding: 6px 8px; margin-top: 10px; }
    .line td { border-bottom: 1px solid #eee9de; }
    .small { font-size: 8.5px; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width:60%;">
            @if($logo)<img src="{{ $logo }}" style="max-height:44px; max-width:180px;" alt=""><br>@endif
            <strong style="font-size:12px">{{ $workspace['name'] }}</strong>
            @if($workspace['legal'])<div class="muted">{{ $workspace['legal'] }}</div>@endif
            @if($workspace['address'])<div class="muted">{!! nl2br(e($workspace['address'])) !!}</div>@endif
            @if($workspace['tax_id'])<div class="muted">Tax ID: {{ $workspace['tax_id'] }}</div>@endif
        </td>
        <td style="text-align:right;">
            <h1>Invoice approval record</h1>
            <div class="muted">Generated {{ now()->format('d M Y, H:i') }}</div>
            <div style="margin-top:8px"><span class="stamp">APPROVED</span></div>
        </td>
    </tr>
</table>

@if($document->is_demo)
    <div class="warn"><strong>Demo data.</strong> This record was extracted by a rule-based demo parser, not an AI model, from a synthetic sample document.</div>
@endif

<h2>Invoice</h2>
<table class="kv">
    <tr><td>Supplier</td><td><strong>{{ $document->supplier_name }}</strong>@if($document->supplier_address)<div class="muted">{!! nl2br(e($document->supplier_address)) !!}</div>@endif @if($document->supplier_tax_id)<div class="muted">Tax ID: {{ $document->supplier_tax_id }}</div>@endif</td></tr>
    <tr><td>Invoice number</td><td>{{ $document->invoice_number }}</td></tr>
    <tr><td>Purchase order</td><td>{{ $document->po_number ?: 'None' }}@if($purchaseOrder) <span class="muted"> · PO value {{ $document->currency }} {{ number_format((float) $purchaseOrder->total, 2) }} ({{ $purchaseOrder->supplier_name }})</span>@endif</td></tr>
    <tr><td>Invoice date</td><td>{{ $document->invoice_date?->format('d M Y') }}</td></tr>
    <tr><td>Due date</td><td>{{ $document->due_date?->format('d M Y') ?? '—' }}</td></tr>
    <tr><td>Payment terms</td><td>{{ $document->payment_terms ?: '—' }}</td></tr>
    <tr><td>Bill to</td><td>{{ $document->bill_to_name ?: '—' }}@if($document->bill_to_address)<div class="muted">{!! nl2br(e($document->bill_to_address)) !!}</div>@endif</td></tr>
    <tr><td>Bank account</td><td>{{ $document->maskedBankAccount() ?: '—' }}</td></tr>
    @if($document->invoice_notes)<tr><td>Notes on invoice</td><td>{{ $document->invoice_notes }}</td></tr>@endif
</table>

<h2>Line items</h2>
<table>
    <thead><tr><th>SKU</th><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @foreach($document->lineItems as $item)
        <tr class="line"><td>{{ $item->sku }}</td><td>{{ $item->description }}</td><td class="num">{{ \App\Support\Format::number($item->quantity, 3) }}</td><td class="num">{{ \App\Support\Format::number($item->unit_price) }}</td><td class="num">{{ number_format((float) $item->amount, 2) }}</td></tr>
    @endforeach
    </tbody>
</table>
<table style="width:240px; margin-left:auto; margin-top:8px;">
    <tr><td>Subtotal</td><td class="num">{{ $document->subtotal !== null ? number_format((float) $document->subtotal, 2) : '—' }}</td></tr>
    @if($document->discount)<tr><td>Discount</td><td class="num">−{{ number_format((float) $document->discount, 2) }}</td></tr>@endif
    @if($document->shipping)<tr><td>Shipping</td><td class="num">{{ number_format((float) $document->shipping, 2) }}</td></tr>@endif
    <tr><td>Tax</td><td class="num">{{ $document->tax !== null ? number_format((float) $document->tax, 2) : '—' }}</td></tr>
    <tr><td><strong>Total ({{ $document->currency }})</strong></td><td class="num"><strong>{{ number_format((float) $document->total, 2) }}</strong></td></tr>
</table>

<h2>Checks</h2>
<div>{{ $document->errorCount() }} error(s), {{ $document->warningCount() }} warning(s) at approval.
@foreach($document->issues() as $issue)<div class="small muted">• {{ $issue['message'] }}</div>@endforeach</div>

<h2>Approval trail</h2>
<table>
    @foreach($document->events->sortBy('created_at')->whereIn('type', ['uploaded', 'extracted', 'edited', 'assigned', 'approved', 'reopened', 'commented']) as $event)
        <tr class="line"><td style="width:24%" class="muted small">{{ $event->created_at->format('d M Y, H:i') }}</td><td>{{ $event->message }}</td><td style="width:22%" class="muted small">{{ $event->user?->name ?? 'System' }}</td></tr>
    @endforeach
</table>
<p>Approved by <strong>{{ $document->reviewer?->name ?? 'a reviewer' }}</strong> on {{ $document->reviewed_at?->format('d M Y, H:i') }}.</p>

@if($document->comments->isNotEmpty())
    <h2>Reviewer notes</h2>
    @foreach($document->comments->sortBy('created_at') as $comment)
        <div class="small"><strong>{{ $comment->user?->name ?? 'User' }}</strong> <span class="muted">{{ $comment->created_at->format('d M Y, H:i') }}</span><br>{{ $comment->body }}</div>
    @endforeach
@endif

<p class="small muted" style="margin-top:24px; border-top:1px solid #e4dfd3; padding-top:8px;">Record {{ $document->uuid }} · source file {{ $document->original_name }} (SHA-256 {{ substr($document->sha256, 0, 16) }}…)</p>
</body>
</html>
