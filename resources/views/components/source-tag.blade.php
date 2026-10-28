@props(['document'])
@if($document->provider === null)
    <span class="text-muted-2 fs-xs">—</span>
@elseif($document->is_demo)
    <span class="tag demo" data-bs-toggle="tooltip" title="Extracted by a rule-based parser, not an AI model.">Demo parser</span>
@else
    <span class="tag live" data-bs-toggle="tooltip" title="{{ $document->provider }} · {{ $document->model }}">Live AI</span>
@endif
