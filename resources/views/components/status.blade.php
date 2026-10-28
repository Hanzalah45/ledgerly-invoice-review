@props(['status'])
<span class="status-pill tone-{{ $status->tone() }}">{{ $status->label() }}</span>
