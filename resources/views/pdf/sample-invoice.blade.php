<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice {{ $invoice['number'] ?? '' }}</title>
<style>
    @page { margin: 40px 46px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1b2733; line-height: 1.45; }
    h1 { font-size: 22px; letter-spacing: 1px; margin: 0 0 4px 0; color: {{ $invoice['mark'][1] ?? '#14746f' }}; }
    .supplier { font-size: 14px; font-weight: bold; margin: 0 0 2px 0; }
    .muted { color: #5b6773; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .meta .label { width: 110px; color: #5b6773; }
    .items { margin-top: 24px; }
    .items th { text-align: left; font-size: 9px; text-transform: uppercase; color: #5b6773; border-bottom: 1.5px solid #1b2733; padding: 6px 4px; }
    .items td { padding: 7px 4px; border-bottom: 1px solid #dde2e8; }
    .num { text-align: right; }
    .totals { width: 270px; margin-left: auto; margin-top: 14px; }
    .totals td { padding: 4px 4px; }
    .totals .grand td { border-top: 1.5px solid #1b2733; font-weight: bold; font-size: 12px; padding-top: 7px; }
    .block { margin-top: 20px; }
    .footer { margin-top: 30px; font-size: 9px; color: #5b6773; border-top: 1px solid #dde2e8; padding-top: 10px; }
    .footer div { margin-bottom: 2px; }
</style>
</head>
<body>
@php
    $fmt = fn ($v) => $invoice['symbol'].number_format($v, 2);
    $color = $invoice['mark'][1] ?? '#14746f';
    $svg = 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 44 44"><rect width="44" height="44" rx="6" fill="'.$color.'"/><path d="M10 31 22 11l12 20Z" fill="#ffffff" fill-opacity=".92"/><rect x="18" y="25" width="8" height="6" fill="'.$color.'"/></svg>');
@endphp
<table>
    <tr>
        <td style="width:55%; vertical-align: top;">
            <table><tr>
                @if(! empty($invoice['mark']))
                    <td style="width:52px; vertical-align: top;"><img src="{{ $svg }}" width="44" height="44" alt=""></td>
                @endif
                <td style="vertical-align: top;">
                    <p class="supplier">{{ $invoice['supplier'] }}</p>
                    <div class="muted">{!! nl2br(e($invoice['address'])) !!}</div>
                    @if(! empty($invoice['taxId']))<div class="muted">Tax ID: {{ $invoice['taxId'] }}</div>@endif
                </td>
            </tr></table>
        </td>
        <td style="width:45%; vertical-align: top;">
            <h1>INVOICE</h1>
            <table class="meta">
                @if($invoice['number'])
                <tr><td class="label">Invoice number</td><td>{{ $invoice['number'] }}</td></tr>
                @endif
                <tr><td class="label">Invoice date</td><td>{{ \Carbon\Carbon::parse($invoice['date'])->format('d M Y') }}</td></tr>
                <tr><td class="label">Due date</td><td>{{ \Carbon\Carbon::parse($invoice['due'])->format('d M Y') }}</td></tr>
                @if(! empty($invoice['po']))
                <tr><td class="label">PO number</td><td>{{ $invoice['po'] }}</td></tr>
                @endif
                <tr><td class="label">Currency</td><td>{{ $invoice['currency'] }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="block">
    <div class="muted">Bill to</div>
    <div><strong>{{ $invoice['billTo'] }}</strong></div>
    @if(! empty($invoice['billAddress']))<div class="muted">{!! nl2br(e($invoice['billAddress'])) !!}</div>@endif
</div>

@php($hasSku = collect($invoice['lines'])->contains(fn ($l) => $l['sku']))
<table class="items">
    <thead>
        <tr>
            @if($hasSku)<th style="width:14%">SKU</th>@endif
            <th style="width:{{ $hasSku ? 34 : 46 }}%">Description</th>
            <th class="num" style="width:12%">Qty</th>
            <th class="num" style="width:20%">Unit price</th>
            <th class="num" style="width:22%">Amount</th>
        </tr>
    </thead>
    <tbody>
    @foreach($invoice['lines'] as $line)
        <tr>
            @if($hasSku)<td>{{ $line['sku'] }}</td>@endif
            <td>{{ $line['description'] }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.') }}</td>
            <td class="num">{{ $fmt($line['unit']) }}</td>
            <td class="num">{{ $fmt($line['amount']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="num">{{ $fmt($invoice['subtotalPrinted']) }}</td></tr>
    @if($invoice['discountPrinted'] > 0)<tr><td>Discount</td><td class="num">-{{ $fmt($invoice['discountPrinted']) }}</td></tr>@endif
    @if($invoice['shippingPrinted'] > 0)<tr><td>Shipping</td><td class="num">{{ $fmt($invoice['shippingPrinted']) }}</td></tr>@endif
    <tr><td>{{ $invoice['taxLabel'] }} ({{ rtrim(rtrim(number_format($invoice['taxRate'], 2), '0'), '.') }}%)</td><td class="num">{{ $fmt($invoice['taxPrinted']) }}</td></tr>
    <tr class="grand"><td>Total due</td><td class="num">{{ $fmt($invoice['totalPrinted']) }}</td></tr>
</table>

<div class="footer">
    <div>Payment terms: {{ $invoice['terms'] ?? 'Net '.\Carbon\Carbon::parse($invoice['date'])->diffInDays(\Carbon\Carbon::parse($invoice['due']), false).' days' }}</div>
    @if(! empty($invoice['bank']))<div>Bank account: {{ $invoice['bank'] }}</div>@endif
    @if(! empty($invoice['notes']))<div>Notes: {{ $invoice['notes'] }}</div>@endif
    <div style="margin-top:8px">This is a synthetic sample invoice generated for demonstration purposes only. It does not represent a real transaction.</div>
</div>
</body>
</html>
