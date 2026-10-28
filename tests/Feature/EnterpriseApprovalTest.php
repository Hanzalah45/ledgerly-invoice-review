<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\ApprovalChain;
use App\Services\Samples\SampleInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnterpriseApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['docai.provider' => 'demo']);
        Setting::flush();
        PurchaseOrder::create(['po_number' => 'PO-77001', 'supplier_name' => 'Atlas Office Supplies Inc.', 'currency' => 'USD', 'total' => 5000, 'status' => 'open']);
    }

    private function tiers(array $tiers = null): void
    {
        Setting::put('approval_tiers', $tiers ?? [
            ['upto' => 1000, 'steps' => ['reviewer']],
            ['upto' => 5000, 'steps' => ['reviewer', 'department_manager']],
            ['upto' => null, 'steps' => ['reviewer', 'department_manager', 'admin']],
        ]);
    }

    private function invoice(float $total = 1543.83): Document
    {
        $definition = collect(SampleInvoices::definitions())->firstWhere('file', 'atlas-office-supplies-AOS-77231.pdf');
        $this->actingAs(User::factory()->reviewer()->create())
            ->post(route('documents.store'), ['files' => [UploadedFile::fake()->createWithContent('a.pdf', SampleInvoices::render($definition))]])->assertRedirect();
        $document = Document::latest('id')->firstOrFail();
        $document->update(['total' => $total]);

        return $document;
    }

    private function approve(Document $document, User $user)
    {
        return $this->actingAs($user)->from(route('documents.show', $document))->post(route('documents.approve', $document));
    }

    public function test_the_total_picks_the_tier_and_without_tiers_the_old_single_step_stays(): void
    {
        $chain = app(ApprovalChain::class);
        $doc = new Document(['total' => 800]);

        $this->assertFalse($chain->usesTiers());
        $this->assertSame(['reviewer'], $chain->stepsFor($doc));

        $this->tiers();
        $this->assertSame(['reviewer'], $chain->stepsFor(new Document(['total' => 1000])));
        $this->assertSame(['reviewer', 'department_manager'], $chain->stepsFor(new Document(['total' => 1000.01])));
        $this->assertSame(['reviewer', 'department_manager', 'admin'], $chain->stepsFor(new Document(['total' => 900000])));
    }

    public function test_a_small_invoice_needs_one_approval(): void
    {
        $this->tiers();
        $document = $this->invoice(900);
        $document->update(['po_number' => null]);

        $this->approve($document, User::factory()->reviewer()->create())->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_a_mid_size_invoice_needs_the_reviewer_then_the_department_manager(): void
    {
        $this->tiers();
        $manager = User::factory()->reviewer()->create(['name' => 'Mia Manager']);
        $dept = Department::create(['name' => 'IT', 'code' => 'IT', 'cost_center' => 'CC-4100', 'manager_id' => $manager->id]);
        $document = $this->invoice(1500);
        $document->update(['department_id' => $dept->id]);
        $clerk = User::factory()->reviewer()->create();

        $this->approve($document, $clerk)->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Ready, $document->fresh()->status, 'Still waiting after step 1 of 2');
        $this->assertSame(['approved', 'pending'], $document->approvals()->pluck('status')->all());
        $this->assertContains('approval_step', $document->events->pluck('type')->all());

        $this->approve($document, User::factory()->reviewer()->create())->assertSessionHasErrors('status');   // not the manager
        $this->approve($document, $manager)->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
        $this->assertSame('Approved for export after 2 approval steps.', $document->fresh()->events->firstWhere('type', 'approved')->message);
    }

    public function test_one_person_cannot_approve_two_steps(): void
    {
        $this->tiers();
        $admin = User::factory()->admin()->create();
        $document = $this->invoice(20000);   // reviewer, department manager, admin
        $document->update(['department_id' => Department::create(['name' => 'Ops', 'code' => 'OPS'])->id]);

        $this->approve($document, $admin)->assertSessionHasNoErrors();            // step 1 (reviewer step, an admin may do it)
        $this->approve($document, $admin)->assertSessionHasErrors('status');      // step 2 by the same person

        $this->assertStringContainsString('Separation of duties', session('errors')->first('status'));
        $this->assertSame(1, $document->approvals()->where('status', 'approved')->count());
    }

    public function test_the_separation_rule_can_be_switched_off(): void
    {
        $this->tiers();
        Setting::put('separate_duties', false);
        $admin = User::factory()->admin()->create();
        $document = $this->invoice(1500);
        $document->update(['department_id' => Department::create(['name' => 'Ops', 'code' => 'OPS'])->id]);   // no manager: admin may act

        $this->approve($document, $admin);
        $this->approve($document, $admin)->assertSessionHasNoErrors();

        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_without_a_department_manager_only_an_admin_can_take_that_step(): void
    {
        $this->tiers();
        $document = $this->invoice(1500);
        $document->update(['department_id' => Department::create(['name' => 'Ops', 'code' => 'OPS'])->id]);
        $this->approve($document, User::factory()->reviewer()->create());

        $this->approve($document, User::factory()->reviewer()->create())->assertSessionHasErrors('status');
        $this->assertStringContainsString('no manager', session('errors')->first('status'));
        $this->approve($document, User::factory()->admin()->create())->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_the_admin_step_needs_an_admin(): void
    {
        $this->tiers([['upto' => null, 'steps' => ['admin']]]);
        $document = $this->invoice(800);

        $this->approve($document, User::factory()->reviewer()->create())->assertSessionHasErrors('status');
        $this->assertSame('This step needs an admin.', session('errors')->first('status'));
        $this->approve($document, User::factory()->admin()->create())->assertSessionHasNoErrors();
    }

    public function test_editing_clears_the_approvals_so_what_was_approved_is_what_is_on_screen(): void
    {
        $this->tiers();
        $manager = User::factory()->reviewer()->create();
        $dept = Department::create(['name' => 'IT', 'code' => 'IT', 'manager_id' => $manager->id]);
        $document = $this->invoice(1500);
        $document->update(['department_id' => $dept->id]);
        $this->approve($document, User::factory()->reviewer()->create());
        $this->assertSame(1, $document->approvals()->where('status', 'approved')->count());

        $this->actingAs(User::factory()->reviewer()->create())->put(route('documents.update', $document), $this->formFor($document, ['supplier_name' => 'Changed Supplier Ltd']))->assertSessionHasNoErrors();

        $this->assertSame(0, $document->approvals()->count());
        $this->assertContains('approvals_reset', $document->fresh()->events->pluck('type')->all());
    }

    public function test_changing_only_the_coding_also_clears_progress_but_does_not_mark_the_extraction_as_edited(): void
    {
        $this->tiers();
        $document = $this->invoice(1500);
        $dept = Department::create(['name' => 'IT', 'code' => 'IT', 'manager_id' => User::factory()->reviewer()->create()->id]);
        $this->approve($document, User::factory()->reviewer()->create());

        $this->actingAs(User::factory()->reviewer()->create())->put(route('documents.update', $document), $this->formFor($document, ['department_id' => $dept->id, 'gl_code' => '6100-IT']))->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame([$dept->id, '6100-IT', false], [$document->department_id, $document->gl_code, $document->human_edited]);
        $this->assertSame(0, $document->approvals()->count());
    }

    public function test_reopen_and_reprocess_clear_the_approvals(): void
    {
        $this->tiers();
        $document = $this->invoice(900);
        $document->update(['po_number' => null]);
        $this->approve($document, User::factory()->reviewer()->create());
        $this->assertSame(1, $document->approvals()->count());

        $this->actingAs(User::factory()->reviewer()->create())->post(route('documents.reopen', $document));
        $this->assertSame(0, $document->approvals()->count());
    }

    public function test_coding_can_be_required_before_the_first_approval(): void
    {
        Setting::put('require_coding', true);
        $document = $this->invoice(900);
        $document->update(['po_number' => null]);
        $reviewer = User::factory()->reviewer()->create();

        $this->approve($document, $reviewer)->assertSessionHasErrors('status');
        $this->assertStringContainsString('department and enter a GL code', session('errors')->first('status'));

        $document->update(['department_id' => Department::create(['name' => 'IT', 'code' => 'IT'])->id, 'gl_code' => '6100']);
        $this->approve($document, $reviewer)->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::Approved, $document->fresh()->status);
    }

    public function test_the_page_shows_the_chain_and_a_helpful_reason_when_you_cannot_act(): void
    {
        $this->tiers();
        $document = $this->invoice(1500);
        $document->update(['department_id' => Department::create(['name' => 'IT', 'code' => 'IT', 'manager_id' => User::factory()->reviewer()->create(['name' => 'Mia Manager'])->id])->id]);

        $this->actingAs(User::factory()->reviewer()->create())->get(route('documents.show', $document))->assertOk()
            ->assertSee('Approval chain')->assertSee('Approve step 1 of 2')->assertSee('Department manager')
            ->assertSee('waiting for approval')->assertSee('later')->assertDontSee('@else', false);

        $this->approve($document, User::factory()->reviewer()->create());
        $this->actingAs(User::factory()->reviewer()->create())->get(route('documents.show', $document))->assertSee('needs the department manager (Mia Manager)');
    }

    public function test_viewers_still_cannot_approve(): void
    {
        $this->tiers();
        $document = $this->invoice(900);

        $this->actingAs(User::factory()->viewer()->create())->post(route('documents.approve', $document))->assertForbidden();
    }

    // ---------------------------------------------------------------- departments and settings

    public function test_an_admin_manages_departments_and_agents_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $reviewer = User::factory()->reviewer()->create();

        $this->actingAs($admin)->post(route('departments.store'), ['name' => 'Finance', 'code' => 'fin', 'cost_center' => 'CC-1', 'manager_id' => $reviewer->id])->assertRedirect(route('departments.index'));
        $dept = Department::firstWhere('code', 'FIN');
        $this->assertSame([$reviewer->id, 'CC-1'], [$dept->manager_id, $dept->cost_center]);

        $this->put(route('departments.update', $dept), ['name' => 'Finance & Tax', 'code' => 'FIN', 'manager_id' => ''])->assertRedirect();
        $this->assertSame(['Finance & Tax', null, false], [$dept->fresh()->name, $dept->fresh()->manager_id, $dept->fresh()->active]);
        $this->get(route('departments.index'))->assertOk()->assertSee('Finance &amp; Tax', false);

        $this->actingAs($reviewer)->get(route('departments.index'))->assertForbidden();
        $this->post(route('departments.store'), ['name' => 'X', 'code' => 'X'])->assertForbidden();
    }

    public function test_department_input_is_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $viewer = User::factory()->viewer()->create();
        Department::create(['name' => 'IT', 'code' => 'IT']);

        $this->actingAs($admin)->post(route('departments.store'), ['name' => '', 'code' => 'bad code!'])->assertSessionHasErrors(['name', 'code']);
        $this->post(route('departments.store'), ['name' => 'Dup', 'code' => 'it'])->assertSessionHasErrors('code');
        $this->post(route('departments.store'), ['name' => 'Mgr', 'code' => 'M1', 'manager_id' => $viewer->id])->assertSessionHasErrors('manager_id');
    }

    public function test_an_admin_can_save_an_approval_policy_from_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.workspace'), [
            'tiers_enabled' => '1', 'tier0_upto' => 2000, 'tier0_steps' => 'reviewer', 'tier1_upto' => 20000, 'tier1_steps' => 'reviewer_manager', 'tier2_steps' => 'reviewer_manager_admin',
            'separate_duties' => '1', 'require_coding' => '1',
        ])->assertSessionHasNoErrors();

        $tiers = app(ApprovalChain::class)->tiers();
        $this->assertSame([2000.0, 20000.0, null], array_column($tiers, 'upto'));
        $this->assertSame(['reviewer', 'department_manager', 'admin'], $tiers[2]['steps']);
        $this->assertTrue((bool) Setting::get('require_coding'));
        $this->get(route('settings'))->assertOk()->assertSee('Approval policy by amount');

        $this->post(route('settings.workspace'), ['tiers_enabled' => '1', 'tier0_upto' => 5000, 'tier1_upto' => 1000])->assertSessionHasErrors('tier1_upto');
        $this->post(route('settings.workspace'), ['tiers_enabled' => '1'])->assertSessionHasErrors('tier0_upto');
        $this->post(route('settings.workspace'), ['tiers_enabled' => '0']);
        $this->assertSame([], app(ApprovalChain::class)->tiers());
    }

    /** @return array<string,mixed> the update form as the browser would send it */
    private function formFor(Document $document, array $override): array
    {
        $document->refresh()->load('lineItems');

        return $override + [
            'supplier_name' => $document->supplier_name, 'invoice_number' => $document->invoice_number,
            'invoice_date' => $document->invoice_date?->toDateString(), 'due_date' => $document->due_date?->toDateString(),
            'currency' => $document->currency, 'subtotal' => $document->subtotal, 'tax' => $document->tax, 'total' => $document->total,
            'discount' => $document->discount, 'shipping' => $document->shipping, 'po_number' => $document->po_number,
            'line_items' => $document->lineItems->map(fn ($i) => ['sku' => $i->sku, 'description' => $i->description, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'amount' => $i->amount, 'tax_rate' => $i->tax_rate])->all(),
        ];
    }
}
