@props(['title', 'subtitle' => null])
<div class="page-head">
    <div class="min-w-0">
        <h1>{{ $title }}</h1>
        @if($subtitle)<p>{{ $subtitle }}</p>@endif
    </div>
    @if(trim((string) $slot) !== '')
        <div class="d-flex flex-wrap gap-2 align-items-center">{{ $slot }}</div>
    @endif
</div>
