@extends('layouts.app')
@section('title', $document->supplier_name ?? $document->original_name)
@section('crumbs')
    <li class="breadcrumb-item"><a href="{{ route('documents.index') }}">Documents</a></li>
    <li class="breadcrumb-item active text-truncate" style="max-width:260px" aria-current="page">{{ $document->original_name }}</li>
@endsection

@section('content')
@use('App\Support\Format')
@php
    use App\Enums\DocumentStatus;
    $status = $document->status;
    $user = auth()->user();
    $fi = fn (string $field) => $issuesByField->get($field, collect());
    $c = fn (string $field) => $document->fieldConfidence($field);
    $money = fn ($v) => $v === null ? '' : number_format((float) $v, 2, '.', '');
    $rows = old('line_items') ?? $document->lineItems->map(fn ($i) => [
        'sku' => $i->sku,
        'description' => $i->description,
        'quantity' => $i->quantity !== null ? Format::number($i->quantity, 3) : '',
        'unit_price' => $money($i->unit_price),
        'tax_rate' => $i->tax_rate !== null ? Format::number($i->tax_rate, 2) : '',
        'amount' => $money($i->amount),
    ])->all();
    $decide = $user->can('decide', $document);
    $blockedByErrors = $document->errorCount() > 0;
    $approveBlocked = $blockedByErrors || (bool) $approveBlockReason;
    $approveHint = $blockedByErrors ? 'Resolve the validation errors first' : ($approveBlockReason ?? '');
    $approveLabel = $plannedSteps->count() > 1 ? 'Approve step '.($plannedSteps->where('status', 'approved')->count() + 1).' of '.$plannedSteps->count() : 'Approve';
@endphp

<x-page-head :title="$document->supplier_name ?? 'Unknown supplier'" :subtitle="$document->original_name.' · '.$document->humanSize().($document->page_count ? ' · '.$document->page_count.' '.\Illuminate\Support\Str::plural('page', $document->page_count) : '')">
    <x-status :status="$status" />
    @if($document->provider)<x-source-tag :document="$document" />@endif
    @if($document->human_edited)<span class="tag edited">Edited by reviewer</span>@endif
</x-page-head>

<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    @if($decide && in_array($status, [DocumentStatus::Ready, DocumentStatus::NeedsReview], true))
        <form method="post" action="{{ route('documents.approve', $document) }}" data-confirm="This marks the record as verified and includes it in CSV and JSON exports." data-confirm-title="Approve this document?" data-confirm-label="Approve">
            @csrf
            <button class="btn btn-primary" @disabled($approveBlocked) @if($approveHint) title="{{ $approveHint }}" @endif><i class="bi bi-check2-circle"></i> {{ $approveLabel }}</button>
        </form>
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#rejectModal"><i class="bi bi-x-circle"></i> Reject</button>
    @endif
    @if($decide && $status->isFinal())
        <form method="post" action="{{ route('documents.reopen', $document) }}" data-confirm="The record returns to review and is removed from default exports until approved again." data-confirm-title="Reopen this document?" data-confirm-label="Reopen">
            @csrf <button class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Reopen</button>
        </form>
    @endif
    @if($decide && $status !== DocumentStatus::Processing)
        <form method="post" action="{{ route('documents.reprocess', $document) }}" data-confirm="Extraction runs again from the original PDF. Any edits you made will be replaced." data-confirm-title="Reprocess this document?" data-confirm-label="Reprocess">
            @csrf <button class="btn btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Reprocess</button>
        </form>
    @endif
    <a href="{{ route('documents.file', $document) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i> Open PDF</a>
    @if($status === DocumentStatus::Approved)
        <a href="{{ route('documents.summary', $document) }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-check"></i> Approval record (PDF)</a>
    @endif
    @if($decide)
        <form method="post" action="{{ route('documents.assign', $document) }}" class="d-flex align-items-center gap-2 ms-lg-2">
            @csrf
            <label for="assigned_to" class="fs-sm text-muted-2 mb-0">Assigned to</label>
            <select id="assigned_to" name="assigned_to" class="form-select form-select-sm" style="width:auto;min-width:150px" data-autosubmit>
                <option value="">Unassigned</option>
                @foreach($assignees as $person)<option value="{{ $person->id }}" @selected($document->assigned_to === $person->id)>{{ $person->name }}</option>@endforeach
            </select>
        </form>
    @elseif($document->assignee)
        <span class="fs-sm text-muted-2">Assigned to <strong class="text-body">{{ $document->assignee->name }}</strong></span>
    @endif
    @can('delete', $document)
        <form method="post" action="{{ route('documents.destroy', $document) }}" class="ms-auto" data-confirm="The PDF, extracted data and its history will be permanently removed." data-confirm-title="Delete this document?" data-confirm-label="Delete" data-confirm-tone="danger">
            @csrf @method('DELETE') <button class="btn btn-outline-secondary text-danger"><i class="bi bi-trash"></i> Delete</button>
        </form>
    @endcan
</div>

@if($plannedSteps->count() > 1)
    <div class="panel mb-3"><div class="panel-body py-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="eyebrow mb-0">Approval chain</div>
            @foreach($plannedSteps as $i => $s)
                @php($done = $s->status === 'approved')
                @php($isNext = ! $done && $plannedSteps->firstWhere('status', '!=', 'approved')?->step_order === $s->step_order)
                <div class="d-flex align-items-center gap-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle fs-xs fw-semibold" style="width:26px;height:26px;{{ $done ? 'background:var(--green);color:#fff' : ($isNext ? 'background:var(--brass);color:#fff' : 'background:var(--paper-2);color:var(--ink-3)') }}">@if($done)<i class="bi bi-check-lg"></i>@else{{ $s->step_order }}@endif</span>
                    <div class="lh-sm"><div class="fw-medium fs-sm">{{ $s->label() }}</div><div class="fs-xs text-muted-2">@if($done){{ $s->actor?->name }} · {{ $s->acted_at?->diffForHumans() }}@elseif($isNext) waiting for approval @else later @endif</div></div>
                </div>
                @if(! $loop->last)<i class="bi bi-chevron-right text-muted-2"></i>@endif
            @endforeach
        </div>
        @if($approveBlockReason && in_array($status, [DocumentStatus::Ready, DocumentStatus::NeedsReview], true))<div class="fs-xs text-muted-2 mt-2"><i class="bi bi-info-circle me-1"></i>{{ $approveBlockReason }}</div>@endif
    </div></div>
@endif
@if($needsAdmin && ! $usesTiers && in_array($status, [DocumentStatus::Ready, DocumentStatus::NeedsReview], true))
    <div class="alert alert-warning d-flex gap-2" role="status"><i class="bi bi-shield-lock"></i>
        <div><strong>Admin approval required.</strong> This invoice is above the workspace approval limit of {{ number_format((float) $threshold, 2) }}. You can review and edit it, but an admin has to approve it.</div></div>
@endif
@if($status === DocumentStatus::Failed)
    <div class="alert alert-danger d-flex gap-2" role="alert"><i class="bi bi-exclamation-octagon-fill"></i>
        <div><strong>Processing failed.</strong> {{ $document->error_message }}</div></div>
@endif
@if($status === DocumentStatus::Rejected)
    <div class="alert alert-secondary d-flex gap-2" role="status"><i class="bi bi-x-circle-fill"></i>
        <div><strong>Rejected</strong> by {{ $document->reviewer?->name ?? 'a reviewer' }} {{ $document->reviewed_at?->diffForHumans() }}.
            @if($document->rejection_reason) Reason: {{ $document->rejection_reason }}@endif</div></div>
@endif
@if($status === DocumentStatus::Approved)
    <div class="alert alert-success d-flex gap-2" role="status"><i class="bi bi-check-circle-fill"></i>
        <div><strong>Approved</strong> by {{ $document->reviewer?->name ?? 'a reviewer' }} {{ $document->reviewed_at?->diffForHumans() }}. This record is included in exports. Reopen it to make changes.</div></div>
@endif
@if($document->is_demo && $document->processed_at)
    <div class="demo-banner"><i class="bi bi-info-circle-fill"></i>
        <div><strong>Demo parser output.</strong> These values were read by regular-expression rules, not an AI model. Confidence scores are rule-match heuristics. Review every field before approving.</div></div>
@endif

<div class="row g-3">
    {{-- Left: source document --}}
    <div class="col-xl-5">
        <div class="panel" style="position: sticky; top: 72px;">
            <ul class="nav nav-tabs px-2 pt-2" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-pdf" type="button" role="tab"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-text" type="button" role="tab"><i class="bi bi-text-left me-1"></i>Extracted text</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab-pdf" role="tabpanel">
                    <iframe class="preview-frame" src="{{ route('documents.file', $document) }}#toolbar=0&navpanes=0" title="PDF preview of {{ $document->original_name }}"></iframe>
                </div>
                <div class="tab-pane fade" id="tab-text" role="tabpanel">
                    @if($document->extracted_text)
                        <pre class="text-dump">{{ $document->extracted_text }}</pre>
                    @else
                        <div class="empty-state"><i class="bi bi-file-earmark-excel"></i><h3>No text available</h3><div>Text appears after a document is processed successfully.</div></div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Right: validation, fields, timeline --}}
    <div class="col-xl-7">
        @if($document->processed_at && $status !== DocumentStatus::Failed)
            <div class="panel panel-flush mb-3">
                <div class="panel-head">
                    <div><h2>Validation</h2><div class="sub">{{ $document->errorCount() }} {{ \Illuminate\Support\Str::plural('error', $document->errorCount()) }} · {{ $document->warningCount() }} {{ \Illuminate\Support\Str::plural('warning', $document->warningCount()) }}</div></div>
                    @if($document->averageConfidence() !== null)<div class="text-end"><div class="fs-xs text-muted-2">Average confidence</div><x-conf :value="$document->averageConfidence()" /></div>@endif
                </div>
                @forelse($document->issues() as $issue)
                    <div class="issue {{ $issue['severity'] }}">
                        <i class="bi {{ $issue['severity'] === 'error' ? 'bi-x-circle-fill' : 'bi-exclamation-triangle-fill' }}"></i>
                        <div><div>{{ $issue['message'] }}</div><div class="code">{{ str_replace('_', ' ', $issue['code']) }}@if($issue['field']) · {{ str_replace(['_', '.'], [' ', ' '], $issue['field']) }}@endif</div></div>
                    </div>
                @empty
                    <div class="issue" style="color: var(--ok)"><i class="bi bi-check-circle-fill" style="color: var(--ok)"></i><div>All checks passed: required fields, formats, dates, totals and duplicates.</div></div>
                @endforelse
            </div>
        @endif

        @if($document->po_number)
            <div class="panel mb-3">
                <div class="panel-head">
                    <div><h2>Purchase order match</h2><div class="sub">{{ $document->po_number }}</div></div>
                    @if($purchaseOrder)
                        @php($poIssues = collect($document->issues())->where('field', 'po_number'))
                        <span class="status-pill tone-{{ $poIssues->contains('severity', 'error') ? 'bad' : ($poIssues->isNotEmpty() ? 'warn' : 'ok') }}">{{ $poIssues->contains('severity', 'error') ? 'Over PO value' : ($poIssues->isNotEmpty() ? 'Check PO' : 'Matches PO') }}</span>
                    @else
                        <span class="status-pill tone-muted">Not on file</span>
                    @endif
                </div>
                @if($purchaseOrder)
                    @php($pct = $purchaseOrder->total > 0 && $document->total !== null ? min(100, round((float) $document->total / (float) $purchaseOrder->total * 100)) : null)
                    <div class="panel-body">
                        <div class="row g-3 fs-sm">
                            <div class="col-sm-4"><div class="text-muted-2">Issued to</div><div class="fw-medium">{{ $purchaseOrder->supplier_name }}</div></div>
                            <div class="col-sm-4"><div class="text-muted-2">PO value</div><div class="fw-medium num">{{ Format::money($purchaseOrder->total, $purchaseOrder->currency) }}</div></div>
                            <div class="col-sm-4"><div class="text-muted-2">This invoice</div><div class="fw-medium num">{{ Format::money($document->total, $document->currency) }}@if($pct !== null) <span class="text-muted-2 fw-normal">· {{ $pct }}% of PO</span>@endif</div></div>
                        </div>
                        @if($pct !== null)<div class="stack mt-3" aria-hidden="true"><i class="tone-{{ $pct >= 100 && $poIssues->contains('severity', 'error') ? 'bad' : 'accent' }}" style="width: {{ $pct }}%"></i></div>@endif
                    </div>
                @else
                    <div class="panel-body fs-sm text-muted-2">This purchase order number is not in the PO register. Add it under <a href="{{ route('purchase-orders.index') }}">Purchase orders</a> and the invoice will be checked against it automatically.</div>
                @endif
            </div>
        @endif

        <form id="documentForm" method="post" action="{{ route('documents.update', $document) }}" data-editable="{{ $canEdit ? '1' : '0' }}" data-tolerance="{{ config('docai.amount_tolerance') }}" novalidate>
            @csrf @method('PUT')
            <div class="panel mb-3">
                <div class="panel-head">
                    <div><h2>Extracted fields</h2><div class="sub">{{ $canEdit ? 'Correct anything that looks wrong. Edited values are marked as verified.' : 'Read-only while the document is '.strtolower($status->label()).'.' }}</div></div>
                </div>
                <div class="panel-body">
                    <div class="form-section">
                        <div class="eyebrow">Supplier &amp; reference</div>
                        <div class="row g-3">
                            <div class="col-md-8"><x-doc-field name="supplier_name" label="Supplier" :value="$document->supplier_name" :confidence="$c('supplier_name')" :issues="$fi('supplier_name')" :disabled="! $canEdit" required /></div>
                            <div class="col-md-4"><x-doc-field name="invoice_number" label="Invoice number" :value="$document->invoice_number" :confidence="$c('invoice_number')" :issues="$fi('invoice_number')" :disabled="! $canEdit" mono required /></div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="eyebrow">Dates</div>
                        <div class="row g-3">
                            <div class="col-md-6"><x-doc-field name="invoice_date" label="Invoice date" type="date" :value="$document->invoice_date?->toDateString()" :confidence="$c('invoice_date')" :issues="$fi('invoice_date')" :disabled="! $canEdit" required /></div>
                            <div class="col-md-6"><x-doc-field name="due_date" label="Due date" type="date" :value="$document->due_date?->toDateString()" :confidence="$c('due_date')" :issues="$fi('due_date')" :disabled="! $canEdit" /></div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="eyebrow">Coding</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="f-department_id">Department</label>
                                <select class="form-select" id="f-department_id" name="department_id" @disabled(! $canEdit)>
                                    <option value="">None</option>
                                    @foreach($departments as $dept)<option value="{{ $dept->id }}" @selected((int) old('department_id', $document->department_id) === $dept->id)>{{ $dept->label() }}@if($dept->cost_center) ({{ $dept->cost_center }})@endif</option>@endforeach
                                </select>
                                @if($document->department?->manager)<div class="form-text">Manager: {{ $document->department->manager->name }}</div>@endif
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="f-gl_code">GL code</label>
                                <input class="form-control mono @error('gl_code') is-invalid @enderror" id="f-gl_code" name="gl_code" value="{{ old('gl_code', $document->gl_code) }}" maxlength="40" placeholder="e.g. 6100-IT" @disabled(! $canEdit)>
                                @error('gl_code')<div class="invalid-feedback">{{ $message }}</div>@else<div class="form-text">Ledger account this invoice is posted to.</div>@enderror
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="eyebrow">Order &amp; billing</div>
                        <div class="row g-3">
                            <div class="col-md-6"><x-doc-field name="po_number" label="PO number" :value="$document->po_number" :confidence="$c('po_number')" :issues="$fi('po_number')" :disabled="! $canEdit" mono /></div>
                            <div class="col-md-6"><x-doc-field name="payment_terms" label="Payment terms" :value="$document->payment_terms" :confidence="$c('payment_terms')" :issues="$fi('payment_terms')" :disabled="! $canEdit" placeholder="e.g. Net 30 days" /></div>
                            <div class="col-md-6"><x-doc-field name="supplier_tax_id" label="Supplier tax ID" :value="$document->supplier_tax_id" :confidence="$c('supplier_tax_id')" :issues="$fi('supplier_tax_id')" :disabled="! $canEdit" mono /></div>
                            <div class="col-md-6">
                                @if($canEdit || blank($document->bank_account))
                                    <x-doc-field name="bank_account" label="Bank account" :value="$document->bank_account" :confidence="$c('bank_account')" :issues="$fi('bank_account')" :disabled="! $canEdit" mono />
                                @else
                                    <div class="field"><label class="form-label mb-1">Bank account</label><input class="form-control mono" value="{{ $document->maskedBankAccount() }}" disabled aria-label="Bank account (masked)"></div>
                                @endif
                            </div>
                            <div class="col-md-6"><x-doc-field name="supplier_address" label="Supplier address" textarea :value="$document->supplier_address" :confidence="$c('supplier_address')" :issues="$fi('supplier_address')" :disabled="! $canEdit" /></div>
                            <div class="col-md-6"><x-doc-field name="bill_to_name" label="Bill to" :value="$document->bill_to_name" :confidence="$c('bill_to_name')" :issues="$fi('bill_to_name')" :disabled="! $canEdit" />
                                <div class="mt-3"><x-doc-field name="bill_to_address" label="Billing address" textarea :value="$document->bill_to_address" :confidence="$c('bill_to_address')" :issues="$fi('bill_to_address')" :disabled="! $canEdit" /></div></div>
                            <div class="col-12"><x-doc-field name="invoice_notes" label="Notes on the invoice" textarea :value="$document->invoice_notes" :confidence="$c('invoice_notes')" :issues="$fi('invoice_notes')" :disabled="! $canEdit" /></div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="eyebrow">Amounts</div>
                        <div class="row g-3">
                            <div class="col-md-4"><x-doc-field name="currency" label="Currency" :value="$document->currency" :confidence="$c('currency')" :issues="$fi('currency')" :disabled="! $canEdit" :options="$currencies" /></div>
                            <div class="col-md-4"><x-doc-field name="subtotal" label="Subtotal" inputmode="decimal" :value="$money($document->subtotal)" :confidence="$c('subtotal')" :issues="$fi('subtotal')" :disabled="! $canEdit" /></div>
                            <div class="col-md-4"><x-doc-field name="discount" label="Discount" inputmode="decimal" :value="$money($document->discount)" :confidence="$c('discount')" :issues="$fi('discount')" :disabled="! $canEdit" /></div>
                            <div class="col-md-4"><x-doc-field name="shipping" label="Shipping" inputmode="decimal" :value="$money($document->shipping)" :confidence="$c('shipping')" :issues="$fi('shipping')" :disabled="! $canEdit" /></div>
                            <div class="col-md-4"><x-doc-field name="tax" label="Tax" inputmode="decimal" :value="$money($document->tax)" :confidence="$c('tax')" :issues="$fi('tax')" :disabled="! $canEdit" /></div>
                            <div class="col-md-4"><x-doc-field name="total" label="Total" inputmode="decimal" :value="$money($document->total)" :confidence="$c('total')" :issues="$fi('total')" :disabled="! $canEdit" required /></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="panel panel-flush mb-3">
                <div class="panel-head">
                    <div><h2>Line items</h2><div class="sub">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('item', count($rows)) }}</div></div>
                    @if($canEdit)<button type="button" class="btn btn-sm btn-outline-secondary" id="addLineItem"><i class="bi bi-plus-lg"></i> Add line</button>@endif
                </div>
                <div class="table-responsive">
                    <table class="table li-table mb-0">
                        <thead><tr><th style="width:120px">SKU</th><th style="min-width:200px">Description</th><th style="width:80px" class="text-end">Qty</th><th style="width:110px" class="text-end">Unit price</th><th style="width:80px" class="text-end">Tax %</th><th style="width:120px" class="text-end">Amount</th>@if($canEdit)<th style="width:40px"></th>@endif</tr></thead>
                        <tbody id="lineItemsBody">
                        @foreach($rows as $i => $row)
                            <tr class="li-row">
                                <td><input data-f="sku" name="line_items[{{ $i }}][sku]" value="{{ $row['sku'] ?? '' }}" class="form-control mono" @disabled(! $canEdit) maxlength="60" aria-label="SKU"></td>
                                <td><input data-f="description" name="line_items[{{ $i }}][description]" value="{{ $row['description'] ?? '' }}" class="form-control" @disabled(! $canEdit) maxlength="255" aria-label="Description"></td>
                                <td><input data-f="quantity" name="line_items[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? '' }}" class="form-control num" inputmode="decimal" @disabled(! $canEdit) aria-label="Quantity"></td>
                                <td><input data-f="unit_price" name="line_items[{{ $i }}][unit_price]" value="{{ $row['unit_price'] ?? '' }}" class="form-control num" inputmode="decimal" @disabled(! $canEdit) aria-label="Unit price"></td>
                                <td><input data-f="tax_rate" name="line_items[{{ $i }}][tax_rate]" value="{{ $row['tax_rate'] ?? '' }}" class="form-control num" inputmode="decimal" @disabled(! $canEdit) aria-label="Tax rate percent"></td>
                                <td><input data-f="amount" name="line_items[{{ $i }}][amount]" value="{{ $row['amount'] ?? '' }}" class="form-control num" inputmode="decimal" @disabled(! $canEdit) aria-label="Amount"></td>
                                @if($canEdit)<td class="text-center"><button type="button" class="btn btn-sm btn-link text-muted-2 p-0" data-remove-row aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div id="lineItemsEmpty" class="empty-state py-4 {{ count($rows) ? 'd-none' : '' }}"><i class="bi bi-list-ul"></i><div>No line items were extracted.@if($canEdit) Add them manually if the invoice has them.@endif</div></div>
                <div class="check-strip" id="checkStrip" aria-live="polite"></div>
            </div>

            @if($canEdit)
                <div class="d-flex justify-content-end gap-2 mb-3">
                    <a href="{{ route('documents.show', $document) }}" class="btn btn-outline-secondary">Discard changes</a>
                    <button type="submit" id="saveBtn" class="btn btn-primary" disabled><i class="bi bi-save"></i> Save changes</button>
                </div>
            @endif
        </form>

        <template id="lineItemTemplate">
            <tr class="li-row">
                <td><input data-f="sku" name="line_items[__][sku]" class="form-control mono" maxlength="60" aria-label="SKU"></td>
                <td><input data-f="description" name="line_items[__][description]" class="form-control" maxlength="255" aria-label="Description" placeholder="Describe the item"></td>
                <td><input data-f="quantity" name="line_items[__][quantity]" class="form-control num" inputmode="decimal" aria-label="Quantity"></td>
                <td><input data-f="unit_price" name="line_items[__][unit_price]" class="form-control num" inputmode="decimal" aria-label="Unit price"></td>
                <td><input data-f="tax_rate" name="line_items[__][tax_rate]" class="form-control num" inputmode="decimal" aria-label="Tax rate percent"></td>
                <td><input data-f="amount" name="line_items[__][amount]" class="form-control num" inputmode="decimal" aria-label="Amount"></td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-link text-muted-2 p-0" data-remove-row aria-label="Remove line"><i class="bi bi-x-lg"></i></button></td>
            </tr>
        </template>

        <div class="panel panel-flush mb-3" id="notes">
            <div class="panel-head"><div><h2>Reviewer notes</h2><div class="sub">Visible to everyone with access to this document</div></div></div>
            @forelse($document->comments as $comment)
                <div class="px-3 py-2 border-bottom fs-sm" style="border-color:var(--line-2)!important">
                    <div class="d-flex justify-content-between"><strong>{{ $comment->user?->name ?? 'Former user' }}</strong><span class="text-muted-2 fs-xs">{{ $comment->created_at->diffForHumans() }}</span></div>
                    <div style="white-space: pre-line">{{ $comment->body }}</div>
                </div>
            @empty
                <div class="px-3 py-3 fs-sm text-muted-2">No notes yet.</div>
            @endforelse
            @if($decide)
                <form method="post" action="{{ route('documents.comment', $document) }}" class="p-3 d-flex gap-2 align-items-start">
                    @csrf
                    <textarea name="body" rows="2" class="form-control" maxlength="2000" required minlength="2" placeholder="Add a note for the next reviewer" aria-label="Note"></textarea>
                    <button class="btn btn-outline-secondary">Add note</button>
                </form>
            @endif
        </div>

        <div class="panel panel-flush">
            <div class="panel-head"><div><h2>History</h2><div class="sub">Uploaded {{ $document->created_at->toDayDateTimeString() }}@if($document->uploader) by {{ $document->uploader->name }}@endif</div></div></div>
            <ul class="timeline">
                @foreach($document->events as $event)
                    @php([$icon, $tone] = $event->icon())
                    <li>
                        <span class="node tone-{{ $tone }}"><i class="bi {{ $icon }}"></i></span>
                        <div>{{ $event->message }}</div>
                        <div class="when">{{ $event->created_at->format('d M Y, H:i') }}@if($event->user) · {{ $event->user->name }}@endif</div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endsection

@push('modals')
<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="{{ route('documents.reject', $document) }}">
            @csrf
            <div class="modal-header border-0 pb-0"><h2 class="modal-title fs-6" id="rejectTitle">Reject this document</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label for="reason" class="form-label">Reason</label>
                <input type="text" id="reason" name="reason" class="form-control" maxlength="200" required minlength="3" placeholder="e.g. Duplicate, wrong entity, unreadable scan">
                <div class="form-text">Rejected documents are excluded from exports. You can reopen them later.</div>
            </div>
            <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Reject document</button></div>
        </form>
    </div>
</div>
@endpush
