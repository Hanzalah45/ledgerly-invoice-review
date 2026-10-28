@props(['value' => null])
@php
    $level = \App\Support\Format::confidenceLevel($value);
    $verified = $value !== null && $value >= 0.995;
    $ticks = $value === null ? 0 : max(1, (int) round($value * 5));
@endphp
@if($value !== null)
    @if($verified)
        <span class="conf verified" title="Confirmed by a reviewer"><i class="bi bi-check2"></i> Verified</span>
    @else
        <span class="conf {{ $level === 'mid' ? 'mid' : ($level === 'low' ? 'low' : '') }}" title="{{ $level === 'low' ? 'Low confidence: please verify this value' : 'Extraction confidence' }}">
            <span class="bar">@for($i = 1; $i <= 5; $i++)<i class="{{ $i <= $ticks ? 'on' : '' }}"></i>@endfor</span>{{ round($value * 100) }}%
        </span>
    @endif
@endif
