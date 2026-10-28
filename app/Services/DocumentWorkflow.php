<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Everything a user can do to a document: upload, edit, approve, reject, reopen, reprocess, delete.
 */
class DocumentWorkflow
{
    private const EDITABLE_FIELDS = [
        'supplier_name', 'invoice_number', 'invoice_date', 'due_date', 'currency', 'subtotal', 'tax', 'total',
        'po_number', 'supplier_address', 'supplier_tax_id', 'bill_to_name', 'bill_to_address',
        'payment_terms', 'discount', 'shipping', 'bank_account', 'invoice_notes',
    ];

    public function __construct(private readonly DocumentProcessor $processor) {}

    public function upload(UploadedFile $file, User $user): Document
    {
        $contents = $file->get();
        if (! str_starts_with((string) $contents, '%PDF-')) {
            throw ValidationException::withMessages(['files' => "{$file->getClientOriginalName()} is not a valid PDF file."]);
        }

        $stored = 'documents/'.Str::uuid()->toString().'.pdf';
        Storage::disk('local')->put($stored, $contents);

        $document = Document::create([
            'user_id' => $user->getKey(),
            'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200),
            'stored_path' => $stored,
            'mime' => 'application/pdf',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'status' => DocumentStatus::Uploaded,
        ]);
        $document->record('uploaded', "Uploaded {$document->original_name} ({$document->humanSize()}).", [], $user);

        ProcessDocument::dispatch($document->getKey());

        return $document->refresh();
    }

    /** Creates an invoice from a PDF that arrived as an email attachment and queues it for extraction. */
    public function importFromEmail(string $contents, string $name, User $owner, string $from, string $subject, ?string $messageId): Document
    {
        $stored = 'documents/'.Str::uuid()->toString().'.pdf';
        Storage::disk('local')->put($stored, $contents);

        $document = Document::create([
            'user_id' => $owner->getKey(),
            'original_name' => $name,
            'stored_path' => $stored,
            'mime' => 'application/pdf',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'status' => DocumentStatus::Uploaded,
            'channel' => 'email',
            'inbound_from' => mb_substr($from, 0, 190),
            'inbound_message_id' => $messageId,
        ]);
        $document->record('uploaded', sprintf('Received by email from %s%s: %s (%s).', $from ?: 'unknown sender', $subject !== '' ? " (\"{$subject}\")" : '', $name, $document->humanSize()), ['channel' => 'email'], $owner);

        ProcessDocument::dispatch($document->getKey());

        return $document->refresh();
    }

    /** Stores a file that already exists on disk (used by the seeder and tests). */
    public function importFromPath(string $path, string $displayName, ?User $user): Document
    {
        $contents = file_get_contents($path);
        $stored = 'documents/'.Str::uuid()->toString().'.pdf';
        Storage::disk('local')->put($stored, $contents);

        $document = Document::create([
            'user_id' => $user?->getKey(),
            'original_name' => $displayName,
            'stored_path' => $stored,
            'mime' => 'application/pdf',
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'status' => DocumentStatus::Uploaded,
        ]);
        $document->record('uploaded', "Uploaded {$displayName} ({$document->humanSize()}).", [], $user);

        return $document;
    }

    /**
     * @param  array<string,mixed>  $data  validated fields plus 'line_items'
     */
    public function update(Document $document, array $data, User $user): Document
    {
        if (! $document->status->isEditable()) {
            throw ValidationException::withMessages(['status' => 'This document can no longer be edited. Reopen it first.']);
        }

        $changed = [];
        DB::transaction(function () use ($document, $data, &$changed) {
            $confidence = $document->confidence ?? [];

            foreach (self::EDITABLE_FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $new = $data[$field] === '' ? null : $data[$field];
                $old = $document->{$field};
                $oldComparable = $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : (is_numeric($old) ? (float) $old : $old);
                $newComparable = is_numeric($new) ? (float) $new : $new;
                if ($oldComparable !== $newComparable) {
                    $changed[] = $field;
                    $document->{$field} = $new;
                    // A value confirmed by a person is, by definition, verified.
                    $confidence[$field] = 1.0;
                }
            }
            $document->confidence = $confidence;

            $columns = ['sku', 'description', 'quantity', 'unit_price', 'amount', 'tax_rate'];
            $before = $document->lineItems()->get($columns)->toArray();
            $rows = collect($data['line_items'] ?? [])
                ->filter(fn ($row) => filled($row['description'] ?? null))
                ->values();
            $document->lineItems()->delete();
            foreach ($rows as $position => $row) {
                $document->lineItems()->create([
                    'position' => $position,
                    'sku' => ($row['sku'] ?? '') !== '' ? $row['sku'] : null,
                    'description' => $row['description'],
                    'quantity' => $row['quantity'] ?? null,
                    'unit_price' => $row['unit_price'] ?? null,
                    'amount' => $row['amount'] ?? null,
                    'tax_rate' => $row['tax_rate'] ?? null,
                    'confidence' => 1.0,
                ]);
            }
            if (json_encode($before) !== json_encode($document->lineItems()->get($columns)->toArray())) {
                $changed[] = 'line_items';
            }

            // Coding fields: not extracted, so no confidence bookkeeping and they do not count as "edited extraction".
            $coding = [];
            if (array_key_exists('department_id', $data) && (int) ($data['department_id'] ?: 0) !== (int) $document->department_id) {
                $document->department_id = $data['department_id'] ?: null;
                $coding[] = 'department';
            }
            if (array_key_exists('gl_code', $data) && (($data['gl_code'] ?: null) !== $document->gl_code)) {
                $document->gl_code = $data['gl_code'] ?: null;
                $coding[] = 'GL code';
            }
            if ($coding) {
                $changed = array_merge($changed, array_map(fn ($c) => $c === 'GL code' ? 'gl_code' : 'department_id', $coding));
            }

            if (array_diff($changed, ['department_id', 'gl_code']) !== []) {
                $document->human_edited = true;
            }
            $document->save();
        });

        if ($changed) {
            app(ApprovalChain::class)->reset($document, 'the invoice was edited', $user);
            $document->record('edited', 'Edited by reviewer: '.implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $changed)).'.', ['fields' => $changed], $user);
        }

        $document->refresh();
        $this->processor->revalidate($document);

        return $document->refresh();
    }

    public function approve(Document $document, User $user): Document
    {
        if (! in_array($document->status, [DocumentStatus::Ready, DocumentStatus::NeedsReview], true)) {
            throw ValidationException::withMessages(['status' => 'Only documents that have been extracted can be approved.']);
        }
        if ($document->errorCount() > 0) {
            throw ValidationException::withMessages(['status' => 'Resolve all validation errors before approving this document.']);
        }
        if (Setting::get('require_coding') && (! $document->department_id || blank($document->gl_code))) {
            throw ValidationException::withMessages(['status' => 'Choose a department and enter a GL code before approving.']);
        }

        $chain = app(ApprovalChain::class);
        $chain->ensureSteps($document);
        $step = $chain->nextPending($document);
        if ($step === null) {
            throw ValidationException::withMessages(['status' => 'There is no approval step left to take.']);
        }
        $chain->assertCanAct($user, $step, $document);

        $step->update(['status' => 'approved', 'acted_by' => $user->getKey(), 'acted_at' => now()]);
        $document->unsetRelation('approvals');
        $steps = $document->approvals;
        $next = $steps->firstWhere('status', 'pending');

        if ($next) {
            $document->record('approval_step', sprintf('Approval %d of %d done (%s). Waiting for the %s.', $step->step_order, $steps->count(), $step->label(), mb_strtolower($next->label())), ['step' => $step->step_order], $user);

            return $document->refresh();
        }

        $document->update([
            'status' => DocumentStatus::Approved,
            'reviewed_by' => $user->getKey(),
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);
        $document->record('approved', $steps->count() > 1 ? sprintf('Approved for export after %d approval steps.', $steps->count()) : 'Approved for export.', [], $user);

        return $document->refresh();
    }

    /** True when a workspace approval threshold is set and this invoice total is above it (amounts compared as entered, no FX). */
    public function needsAdminApproval(Document $document): bool
    {
        $threshold = Setting::get('approval_threshold');

        return is_numeric($threshold) && (float) $threshold > 0 && $document->total !== null && (float) $document->total > (float) $threshold;
    }

    public function assign(Document $document, ?User $assignee, User $actor): Document
    {
        if ($assignee && ! $assignee->canReview()) {
            throw ValidationException::withMessages(['assigned_to' => 'Documents can only be assigned to admins or reviewers.']);
        }

        $document->update(['assigned_to' => $assignee?->getKey()]);
        $document->record('assigned', $assignee ? "Assigned to {$assignee->name}." : 'Assignment removed.', ['assignee' => $assignee?->getKey()], $actor);

        return $document->refresh();
    }

    public function comment(Document $document, User $user, string $body): DocumentComment
    {
        $comment = $document->comments()->create(['user_id' => $user->getKey(), 'body' => trim($body)]);
        $document->record('commented', 'Added a note.', ['comment_id' => $comment->getKey()], $user);

        return $comment;
    }

    public function reject(Document $document, User $user, string $reason): Document
    {
        if ($document->status->isFinal()) {
            throw ValidationException::withMessages(['status' => 'This document has already been decided. Reopen it first.']);
        }

        $document->update([
            'status' => DocumentStatus::Rejected,
            'reviewed_by' => $user->getKey(),
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
        $document->record('rejected', "Rejected: {$reason}", ['reason' => $reason], $user);

        return $document->refresh();
    }

    public function reopen(Document $document, User $user): Document
    {
        if (! $document->status->isFinal()) {
            throw ValidationException::withMessages(['status' => 'Only approved or rejected documents can be reopened.']);
        }

        app(ApprovalChain::class)->reset($document, 'the invoice was reopened', $user);
        $document->update(['status' => DocumentStatus::NeedsReview, 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null]);
        $document->record('reopened', 'Reopened for review.', [], $user);
        $this->processor->revalidate($document->refresh());

        return $document->refresh();
    }

    public function reprocess(Document $document, User $user): Document
    {
        if ($document->status === DocumentStatus::Processing) {
            throw ValidationException::withMessages(['status' => 'This document is already being processed.']);
        }

        app(ApprovalChain::class)->reset($document, 'the invoice is being reprocessed', $user);
        $document->update(['status' => DocumentStatus::Uploaded, 'reviewed_by' => null, 'reviewed_at' => null, 'rejection_reason' => null]);
        $document->record('reprocessed', 'Reprocessing requested. Previous edits were replaced.', [], $user);
        ProcessDocument::dispatch($document->getKey());

        return $document->refresh();
    }

    public function delete(Document $document, User $user): void
    {
        $name = $document->original_name;
        Storage::disk('local')->delete($document->stored_path);
        $document->delete();

        \App\Models\DocumentEvent::create([
            'document_id' => null,
            'user_id' => $user->getKey(),
            'type' => 'deleted',
            'message' => "Deleted {$name}.",
        ]);
    }
}
