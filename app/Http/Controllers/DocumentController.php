<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Http\Requests\UpdateDocumentRequest;
use App\Http\Requests\UploadDocumentsRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApprovalChain;
use App\Services\DocumentWorkflow;
use App\Services\Extraction\ExtractionManager;
use App\Support\DocumentFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentWorkflow $workflow) {}

    public function index(Request $request): View
    {
        $filters = DocumentFilters::fromRequest($request);
        $documents = DocumentFilters::query($filters)->with('assignee:id,name')->paginate(12)->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'assignees' => User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_REVIEWER])->orderBy('name')->get(['id', 'name']),
            'canBulk' => $request->user()->canReview(),
            'isAdmin' => $request->user()->isAdmin(),
            'filters' => $filters,
            'statuses' => DocumentStatus::options(),
            'statusCounts' => Document::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
        ]);
    }

    public function create(ExtractionManager $providers): View
    {
        $this->authorize('create', Document::class);

        return view('documents.create', [
            'active' => $providers->active(),
            'live' => $providers->isLive(),
            'maxMb' => round(config('docai.max_upload_kb') / 1024),
            'maxFiles' => config('docai.max_files_per_upload'),
            'samples' => $this->sampleFiles(),
        ]);
    }

    public function store(UploadDocumentsRequest $request): RedirectResponse
    {
        $documents = [];
        $failures = [];
        foreach ($request->file('files') as $file) {
            try {
                $documents[] = $this->workflow->upload($file, $request->user());
            } catch (ValidationException $e) {
                $failures[] = collect($e->errors())->flatten()->first();
            }
        }

        if (! $documents) {
            return back()->withErrors(['files' => $failures ?: ['No files could be uploaded.']]);
        }

        $redirect = count($documents) === 1
            ? redirect()->route('documents.show', $documents[0])
            : redirect()->route('documents.index');

        $message = count($documents) === 1
            ? 'Document uploaded and processed.'
            : count($documents).' documents uploaded and processed.';
        if ($failures) {
            $message .= ' '.count($failures).' file(s) were skipped: '.implode(' ', $failures);
        }

        return $redirect->with($failures ? 'warning' : 'success', $message);
    }

    public function show(Document $document): View
    {
        $this->authorize('view', $document);
        $document->load(['lineItems', 'events.user', 'uploader', 'reviewer', 'assignee', 'comments.user', 'department.manager', 'approvals.actor']);

        $chain = app(ApprovalChain::class);
        $planned = $document->approvals->isNotEmpty()
            ? $document->approvals
            : collect($chain->stepsFor($document))->map(fn ($type, $i) => new DocumentApproval(['step_order' => $i + 1, 'step_type' => $type, 'status' => 'pending']));
        $pending = $planned->firstWhere('status', 'pending');
        $approveBlockReason = null;
        if ($pending && in_array($document->status, [DocumentStatus::Ready, DocumentStatus::NeedsReview], true)) {
            try {
                $chain->assertCanAct(auth()->user(), $pending, $document);
            } catch (ValidationException $e) {
                $approveBlockReason = $e->errors()['status'][0] ?? null;
            }
        }

        return view('documents.show', [
            'plannedSteps' => $planned->values(),
            'approveBlockReason' => $approveBlockReason,
            'usesTiers' => $chain->usesTiers(),
            'departments' => Department::where('active', true)->orderBy('name')->get(),
            'purchaseOrder' => $document->purchaseOrder(),
            'assignees' => User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_REVIEWER])->orderBy('name')->get(['id', 'name']),
            'needsAdmin' => $this->workflow->needsAdminApproval($document) && ! auth()->user()->isAdmin(),
            'threshold' => Setting::get('approval_threshold'),
            'document' => $document,
            'issuesByField' => collect($document->issues())->groupBy(fn ($i) => $i['field'] ?? '_general'),
            'canEdit' => $document->status->isEditable() && auth()->user()->can('update', $document),
            'currencies' => config('docai.currencies'),
        ]);
    }

    public function update(UpdateDocumentRequest $request, Document $document): RedirectResponse
    {
        $this->workflow->update($document, $request->validated(), $request->user());

        return redirect()->route('documents.show', $document)->with('success', 'Changes saved and the document was validated again.');
    }

    public function file(Document $document): StreamedResponse
    {
        $this->authorize('view', $document);
        abort_unless(Storage::disk('local')->exists($document->stored_path), 404);

        return Storage::disk('local')->response(
            $document->stored_path,
            $document->original_name,
            [
                'Content-Type' => 'application/pdf',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline'
        );
    }

    public function approve(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('decide', $document);
        $this->workflow->approve($document, $request->user());

        return back()->with('success', 'Document approved. It is now included in exports.');
    }

    public function reject(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('decide', $document);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $this->workflow->reject($document, $request->user(), $data['reason']);

        return back()->with('success', 'Document rejected.');
    }

    public function reopen(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('decide', $document);
        $this->workflow->reopen($document, $request->user());

        return back()->with('success', 'Document reopened for review.');
    }

    public function reprocess(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('decide', $document);
        $this->workflow->reprocess($document, $request->user());

        return back()->with('success', 'Document sent for reprocessing.');
    }

    public function assign(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('decide', $document);
        $data = $request->validate(['assigned_to' => ['nullable', 'integer', 'exists:users,id']]);
        $assignee = filled($data['assigned_to'] ?? null) ? User::findOrFail($data['assigned_to']) : null;
        $this->workflow->assign($document, $assignee, $request->user());

        return back()->with('success', $assignee ? "Assigned to {$assignee->name}." : 'Assignment removed.');
    }

    public function comment(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('decide', $document);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:2000']]);
        $this->workflow->comment($document, $request->user(), $data['body']);

        return back()->with('success', 'Note added.');
    }

    /** Printable approval record (PDF) for an approved invoice, carrying the workspace logo and audit trail. */
    public function summary(Document $document)
    {
        $this->authorize('view', $document);
        abort_unless($document->status === DocumentStatus::Approved, 404);

        $document->load(['lineItems', 'events.user', 'reviewer', 'uploader', 'comments.user']);
        $logo = null;
        if (($path = Setting::get('logo_path')) && Storage::disk('local')->exists($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $logo = 'data:image/'.($ext === 'jpg' ? 'jpeg' : $ext).';base64,'.base64_encode(Storage::disk('local')->get($path));
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.approval-summary', [
            'document' => $document,
            'purchaseOrder' => $document->purchaseOrder(),
            'logo' => $logo,
            'workspace' => [
                'name' => Setting::workspaceName(),
                'legal' => Setting::get('company_legal_name'),
                'address' => Setting::get('company_address'),
                'tax_id' => Setting::get('company_tax_id'),
            ],
        ])->setPaper('a4');

        $document->record('exported', 'Downloaded the approval record (PDF).', [], auth()->user());

        return $pdf->download('approval-record-'.Str::slug($document->invoice_number ?: $document->uuid).'.pdf');
    }

    /** Bulk actions on the selected rows. Each document is handled on its own; failures are reported, not hidden. */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject', 'assign', 'reprocess', 'delete'])],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['string', 'regex:/^[0-9a-f-]{36}$/'],
            'reason' => ['nullable', 'string', 'min:3', 'max:200'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $user = $request->user();
        abort_unless($data['action'] === 'delete' ? $user->isAdmin() : $user->canReview(), 403);
        if ($data['action'] === 'reject' && blank($data['reason'] ?? null)) {
            return back()->withErrors(['reason' => 'Give a reason for rejecting the selected documents.']);
        }

        $done = 0;
        $skipped = [];
        foreach (Document::whereIn('uuid', $data['ids'])->get() as $document) {
            try {
                match ($data['action']) {
                    'approve' => $this->workflow->approve($document, $user),
                    'reject' => $this->workflow->reject($document, $user, $data['reason']),
                    'assign' => $this->workflow->assign($document, filled($data['assigned_to'] ?? null) ? User::findOrFail($data['assigned_to']) : null, $user),
                    'reprocess' => $this->workflow->reprocess($document, $user),
                    'delete' => $this->workflow->delete($document, $user),
                };
                $done++;
            } catch (ValidationException $e) {
                $skipped[] = ($document->invoice_number ?: $document->original_name).': '.collect($e->errors())->flatten()->first();
            }
        }

        $verb = ['approve' => 'Approved', 'reject' => 'Rejected', 'assign' => 'Assigned', 'reprocess' => 'Sent for reprocessing', 'delete' => 'Deleted'][$data['action']];
        $message = "{$verb} {$done} document(s).";
        if ($skipped) {
            $message .= ' Skipped '.count($skipped).': '.implode(' · ', array_slice($skipped, 0, 3)).(count($skipped) > 3 ? ' …' : '');
        }

        return back()->with($skipped ? 'warning' : 'success', $message);
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);
        $this->workflow->delete($document, $request->user());

        return redirect()->route('documents.index')->with('success', 'Document deleted.');
    }

    /** @return array<int,array{name:string,size:string}> */
    private function sampleFiles(): array
    {
        $dir = storage_path('app/samples');
        if (! is_dir($dir)) {
            return [];
        }

        return collect(glob($dir.DIRECTORY_SEPARATOR.'*.pdf') ?: [])
            ->map(fn ($path) => ['name' => basename($path), 'size' => round(filesize($path) / 1024).' KB'])
            ->values()
            ->all();
    }
}
