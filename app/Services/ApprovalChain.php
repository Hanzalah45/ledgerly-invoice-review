<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Who has to approve an invoice, in what order.
 *
 * Without configured tiers the old behaviour stays: one step, done by a reviewer, or by an admin when the total is above
 * the workspace approval limit. With tiers (Settings > Approval policy) the total picks a tier and each tier is an ordered
 * list of steps: reviewer, department manager, admin. Separation of duties (on by default with tiers) means one person
 * cannot approve two steps of the same invoice. Editing the invoice, reopening or reprocessing it clears all approvals,
 * so what was approved is always exactly what is on screen.
 */
class ApprovalChain
{
    public const PRESETS = [
        'reviewer' => ['label' => 'Reviewer', 'steps' => ['reviewer']],
        'reviewer_manager' => ['label' => 'Reviewer, then department manager', 'steps' => ['reviewer', 'department_manager']],
        'reviewer_manager_admin' => ['label' => 'Reviewer, department manager, then admin', 'steps' => ['reviewer', 'department_manager', 'admin']],
        'admin' => ['label' => 'Admin only', 'steps' => ['admin']],
    ];

    /** @return list<array{upto:?float,steps:list<string>}> sorted by limit, open-ended tier last */
    public function tiers(): array
    {
        $tiers = collect((array) Setting::get('approval_tiers', []))
            ->filter(fn ($t) => is_array($t) && ! empty($t['steps']))
            ->map(fn ($t) => ['upto' => isset($t['upto']) && $t['upto'] !== null && $t['upto'] !== '' ? (float) $t['upto'] : null, 'steps' => array_values($t['steps'])])
            ->sortBy(fn ($t) => $t['upto'] ?? INF)
            ->values()->all();

        return $tiers;
    }

    public function usesTiers(): bool
    {
        return $this->tiers() !== [];
    }

    /** @return list<string> */
    public function stepsFor(Document $document): array
    {
        if (! $this->usesTiers()) {
            return app(DocumentWorkflow::class)->needsAdminApproval($document) ? ['admin'] : ['reviewer'];
        }

        $total = (float) $document->total;
        $tiers = $this->tiers();
        foreach ($tiers as $tier) {
            if ($tier['upto'] === null || $total <= $tier['upto']) {
                return $tier['steps'];
            }
        }

        return $tiers[array_key_last($tiers)]['steps'];
    }

    /** Creates the pending steps the first time someone acts on the invoice. */
    public function ensureSteps(Document $document): Collection
    {
        if ($document->approvals()->doesntExist()) {
            foreach ($this->stepsFor($document) as $i => $type) {
                $document->approvals()->create(['step_order' => $i + 1, 'step_type' => $type]);
            }
        }

        return $document->unsetRelation('approvals')->approvals;
    }

    public function nextPending(Document $document): ?DocumentApproval
    {
        return $document->approvals()->where('status', 'pending')->orderBy('step_order')->first();
    }

    /** Clears every approval step. Records an event only when someone had already approved a step. */
    public function reset(Document $document, string $reason, ?User $by = null): void
    {
        $hadProgress = $document->approvals()->where('status', 'approved')->exists();
        $document->approvals()->delete();
        $document->unsetRelation('approvals');
        if ($hadProgress) {
            $document->record('approvals_reset', "Approval progress was cleared because {$reason}.", [], $by);
        }
    }

    /** @throws ValidationException */
    public function assertCanAct(User $user, DocumentApproval $step, Document $document): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['status' => $message]);

        if ($this->usesTiers() && Setting::get('separate_duties', true)
            && $document->approvals()->where('acted_by', $user->getKey())->exists()) {
            $fail('Separation of duties: you already approved an earlier step of this invoice. A different person must approve this one.');
        }

        switch ($step->step_type) {
            case 'reviewer':
                if (! $user->canReview()) {
                    $fail('This step needs a reviewer or an admin.');
                }
                break;

            case 'department_manager':
                $manager = $document->department?->manager_id;
                if ($manager && $manager !== $user->getKey()) {
                    $fail(sprintf('This step needs the department manager (%s).', $document->department->manager?->name ?? 'the assigned manager'));
                }
                if (! $manager && ! $user->isAdmin()) {
                    $fail($document->department
                        ? 'The department has no manager set, so an admin has to approve this step.'
                        : 'Choose a department first. Without one an admin has to approve the department manager step.');
                }
                break;

            case 'admin':
                if (! $user->isAdmin()) {
                    $fail($this->usesTiers()
                        ? 'This step needs an admin.'
                        : sprintf('Invoices over %s need an admin to approve them.', number_format((float) Setting::get('approval_threshold'), 2)));
                }
                break;
        }
    }

    /** Short sentence for the UI: where this invoice is in its chain. */
    public function summary(Document $document): ?string
    {
        $steps = $document->approvals;
        if ($steps->isEmpty() || $steps->count() < 2) {
            return null;
        }
        $done = $steps->where('status', 'approved')->count();
        $next = $steps->firstWhere('status', 'pending');

        return $next ? "{$done} of {$steps->count()} approvals done. Next: {$next->label()}." : 'All approvals done.';
    }
}
