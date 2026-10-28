@props([
    'name', 'label', 'value' => null, 'type' => 'text', 'confidence' => null, 'issues' => collect(),
    'disabled' => false, 'mono' => false, 'options' => null, 'placeholder' => null, 'inputmode' => null, 'required' => false,
    'textarea' => false, 'rows' => 3,
])
@php
    $severity = $issues->contains(fn ($i) => $i['severity'] === 'error') ? 'error' : ($issues->isNotEmpty() ? 'warning' : null);
    $hasValidationError = $errors->has($name);
    $current = old($name, $value);
@endphp
<div class="field {{ $hasValidationError || $severity === 'error' ? 'has-error' : ($severity === 'warning' ? 'has-warning' : '') }}">
    <div class="d-flex justify-content-between align-items-baseline">
        <label for="f-{{ $name }}" class="form-label mb-1">{{ $label }}@if($required)<span class="text-danger" aria-hidden="true"> *</span>@endif</label>
        <x-conf :value="$confidence" />
    </div>

    @if($options !== null)
        <select id="f-{{ $name }}" name="{{ $name }}" class="form-select" @disabled($disabled)>
            <option value="">—</option>
            @foreach($options as $option)
                <option value="{{ $option }}" @selected($current === $option)>{{ $option }}</option>
            @endforeach
            @if($current && ! in_array($current, $options, true))<option value="{{ $current }}" selected>{{ $current }}</option>@endif
        </select>
    @elseif($textarea)
        <textarea id="f-{{ $name }}" name="{{ $name }}" rows="{{ $rows }}" class="form-control {{ $hasValidationError ? 'is-invalid' : '' }}" @disabled($disabled) @if($placeholder) placeholder="{{ $placeholder }}" @endif>{{ $current }}</textarea>
    @else
        <input id="f-{{ $name }}" name="{{ $name }}" type="{{ $type }}" value="{{ $current }}"
               class="form-control {{ $mono ? 'mono' : '' }} {{ $inputmode ? 'num' : '' }} {{ $hasValidationError ? 'is-invalid' : '' }}"
               @if($inputmode) inputmode="{{ $inputmode }}" style="font-variant-numeric: tabular-nums" @endif
               @if($placeholder) placeholder="{{ $placeholder }}" @endif
               @disabled($disabled) autocomplete="off">
    @endif

    @error($name)<div class="field-note error"><i class="bi bi-x-circle-fill"></i><span>{{ $message }}</span></div>@enderror
    @foreach($issues as $issue)
        <div class="field-note {{ $issue['severity'] }}"><i class="bi {{ $issue['severity'] === 'error' ? 'bi-x-circle-fill' : 'bi-exclamation-triangle-fill' }}"></i><span>{{ $issue['message'] }}</span></div>
    @endforeach
</div>
