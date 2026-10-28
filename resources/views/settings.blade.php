@extends('layouts.app')
@section('title', 'Settings')
@section('crumbs')<li class="breadcrumb-item active" aria-current="page">Settings</li>@endsection

@section('content')
<x-page-head title="Settings" subtitle="Workspace branding, approval rules, extraction providers and members." />

<div class="row g-3">
    <div class="col-lg-7">
        {{-- Branding & company --}}
        <div class="panel mb-3">
            <div class="panel-head"><div><h2>Workspace &amp; branding</h2><div class="sub">Shown in the sidebar and on approval records (PDF)</div></div></div>
            <div class="panel-body">
                <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom" style="border-color:var(--line-2)!important">
                    <div class="logo-preview">@if($workspace['has_logo'])<img src="{{ route('brand.logo') }}?v={{ time() }}" alt="Current workspace logo">@else<span class="text-muted-2 fs-xs text-center px-2">No logo</span>@endif</div>
                    <div class="flex-grow-1">
                        <form method="post" action="{{ route('settings.logo') }}" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
                            @csrf
                            <input type="file" name="logo" id="logoInput" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm @error('logo') is-invalid @enderror" style="max-width:260px" required aria-label="Logo file">
                            <button class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> {{ $workspace['has_logo'] ? 'Replace logo' : 'Upload logo' }}</button>
                            @error('logo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </form>
                        <div class="form-text">PNG, JPG or WebP · up to 1 MB · 64 to 2400 px. SVG is not accepted.</div>
                        @if($workspace['has_logo'])
                            <form method="post" action="{{ route('settings.logo.delete') }}" data-confirm="The default Ledgerly mark will be used again." data-confirm-title="Remove the logo?" data-confirm-label="Remove" data-confirm-tone="danger" class="mt-1">
                                @csrf @method('DELETE') <button class="btn btn-sm btn-link text-danger p-0">Remove logo</button>
                            </form>
                        @endif
                    </div>
                </div>

                <form method="post" action="{{ route('settings.workspace') }}" class="row g-3">
                    @csrf
                    <div class="col-md-6"><label class="form-label" for="company_name">Display name</label><input class="form-control @error('company_name') is-invalid @enderror" id="company_name" name="company_name" maxlength="80" value="{{ old('company_name', $workspace['company_name']) }}" placeholder="{{ config('app.name') }}">@error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-md-6"><label class="form-label" for="company_legal_name">Legal name</label><input class="form-control" id="company_legal_name" name="company_legal_name" maxlength="120" value="{{ old('company_legal_name', $workspace['company_legal_name']) }}"></div>
                    <div class="col-md-8"><label class="form-label" for="company_address">Address</label><textarea class="form-control" id="company_address" name="company_address" rows="2" maxlength="300">{{ old('company_address', $workspace['company_address']) }}</textarea></div>
                    <div class="col-md-4"><label class="form-label" for="company_tax_id">Tax ID</label><input class="form-control mono" id="company_tax_id" name="company_tax_id" maxlength="40" value="{{ old('company_tax_id', $workspace['company_tax_id']) }}"></div>

                    <div class="col-12"><div class="eyebrow mt-2">Approval rules</div></div>
                    <div class="col-md-6">
                        <label class="form-label" for="approval_threshold">Admin approval above</label>
                        <input class="form-control num @error('approval_threshold') is-invalid @enderror" id="approval_threshold" name="approval_threshold" inputmode="decimal" value="{{ old('approval_threshold', $workspace['approval_threshold']) }}" placeholder="No limit">
                        @error('approval_threshold')<div class="invalid-feedback">{{ $message }}</div>@else<div class="form-text">Reviewers cannot approve invoices with a total above this amount. Amounts are compared as printed, with no currency conversion.</div>@enderror
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="require_po" name="require_po" value="1" @checked(old('require_po', $workspace['require_po']))>
                            <label class="form-check-label" for="require_po">Warn when an invoice has no PO number</label>
                        </div>
                    </div>
                    <div class="col-12"><div class="eyebrow mt-2">Approval policy by amount</div></div>
                    <div class="col-12">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" id="tiers_enabled" name="tiers_enabled" value="1" @checked(old('tiers_enabled', $policy['enabled']))>
                            <label class="form-check-label" for="tiers_enabled">Use approval chains instead of the single limit above</label>
                        </div>
                        <div class="form-text mb-3">Each tier picks who must approve, in order. A department manager step uses the manager set on the invoice's department. Amounts are compared as printed, with no currency conversion.</div>
                        @foreach($policy['tiers'] as $i => $tier)
                            <div class="row g-2 align-items-end mb-2">
                                <div class="col-md-4">
                                    <label class="form-label" for="tier{{ $i }}_upto">{{ $i === 2 ? 'Everything above the previous tier' : ($i === 0 ? 'Up to (total)' : 'Up to (total)') }}</label>
                                    @if($i < 2)<input class="form-control num @error('tier'.$i.'_upto') is-invalid @enderror" id="tier{{ $i }}_upto" name="tier{{ $i }}_upto" inputmode="decimal" value="{{ old('tier'.$i.'_upto', $tier['upto']) }}" placeholder="e.g. {{ $i === 0 ? '5000' : '25000' }}">@error('tier'.$i.'_upto')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    @else<input class="form-control" value="No upper limit" disabled>@endif
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label" for="tier{{ $i }}_steps">Approvals required</label>
                                    <select class="form-select" id="tier{{ $i }}_steps" name="tier{{ $i }}_steps">
                                        @foreach(\App\Services\ApprovalChain::PRESETS as $key => $preset)<option value="{{ $key }}" @selected(old('tier'.$i.'_steps', $tier['preset']) === $key)>{{ $preset['label'] }}</option>@endforeach
                                    </select>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="separate_duties" name="separate_duties" value="1" @checked(old('separate_duties', $policy['separate_duties']))>
                            <label class="form-check-label" for="separate_duties">One person cannot approve two steps</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="require_coding" name="require_coding" value="1" @checked(old('require_coding', $policy['require_coding']))>
                            <label class="form-check-label" for="require_coding">Require department and GL code before approval</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary">Save workspace settings</button></div>
                </form>
            </div>
        </div>

        <div class="panel panel-flush mb-3">
            <div class="panel-head"><div><h2>Extraction provider</h2><div class="sub">Requested: <span class="mono">{{ $requested }}</span></div></div></div>
            @foreach($providers as $p)
                <div class="d-flex align-items-start gap-3 px-3 py-3 {{ ! $loop->last ? 'border-bottom' : '' }}" style="border-color:var(--line-2)!important">
                    <i class="bi {{ $p['name'] === 'demo' ? 'bi-braces' : 'bi-cpu' }} fs-4 text-muted-2"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold d-flex flex-wrap gap-2 align-items-center">{{ $p['label'] }}
                            @if($p['active'])<span class="status-pill tone-ok">In use</span>@endif
                            @if(! $p['configured'])<span class="status-pill tone-muted">No API key</span>@endif
                        </div>
                        <div class="fs-sm text-muted-2">
                            @if($p['name'] === 'demo')
                                Local regular-expression rules over the PDF text. Always available, never calls an external service, and is clearly labelled as demo output.
                            @else
                                Live extraction. Set <span class="mono">DOCAI_{{ strtoupper($p['name']) }}_API_KEY</span> and <span class="mono">DOCAI_PROVIDER={{ $p['name'] }}</span> in <span class="mono">.env</span>.
                                @if($p['model'])Model: <span class="mono">{{ $p['model'] }}</span>.@endif
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="px-3 py-2 border-top fs-xs text-muted-2" style="background:var(--surface-2);border-color:var(--line-2)!important">API keys are never shown here or stored in the database. If a live provider has no key, uploads fall back to the demo parser and say so in the document history.</div>
        </div>

        <div class="panel panel-flush">
            <div class="panel-head"><h2>Limits &amp; validation</h2></div>
            <table class="table table-clean mb-0">
                <tbody>
                @foreach($limits as $label => $value)
                    <tr><td class="text-muted-2" style="width:55%">{{ $label }}</td><td class="fw-medium">{{ $value }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="panel panel-flush">
            <div class="panel-head"><div><h2>Members</h2><div class="sub">Role-based access</div></div></div>
            <table class="table table-clean mb-0">
                <tbody>
                @foreach($users as $member)
                    <tr>
                        <td><div class="d-flex align-items-center gap-2"><span class="avatar">{{ $member->initials() }}</span><div><div class="cell-main">{{ $member->name }}</div><div class="cell-sub">{{ $member->email }}</div></div></div></td>
                        <td class="text-end"><span class="status-pill tone-{{ $member->role === 'admin' ? 'accent' : ($member->role === 'reviewer' ? 'info' : 'muted') }}">{{ $member->roleLabel() }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div class="px-3 py-3 border-top fs-sm text-muted-2" style="border-color:var(--line-2)!important">
                <div><strong class="text-body">Admin</strong> manages settings, approves any invoice and can delete.</div>
                <div><strong class="text-body">Reviewer</strong> uploads, edits, assigns, notes, approves (up to the limit) and rejects.</div>
                <div><strong class="text-body">Viewer</strong> has read-only access and can export.</div>
            </div>
        </div>
    </div>
</div>
@endsection
