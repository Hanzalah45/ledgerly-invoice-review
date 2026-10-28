<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentProcessor;
use App\Services\DocumentWorkflow;
use App\Services\Extraction\PdfTextExtractor;
use App\Services\Validation\DocumentValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProcurementAndBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function po(array $override = []): PurchaseOrder
    {
        return PurchaseOrder::create($override + ['po_number' => 'PO-1001', 'supplier_name' => 'Acme Supplies Inc.', 'currency' => 'USD', 'total' => 1000, 'status' => 'open']);
    }

    private function codes(Document $document): array
    {
        return array_column(DocumentValidator::fromConfig()->purchaseOrderIssues($document), 'code');
    }

    // ------------------------------------------------------------------ Branding

    public function test_admin_can_upload_serve_replace_and_remove_a_logo(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.logo'), ['logo' => UploadedFile::fake()->image('logo.png', 300, 300)])->assertSessionHasNoErrors();

        $path = Setting::get('logo_path');
        $this->assertMatchesRegularExpression('#^branding/logo-[A-Za-z0-9]{12}\.png$#', $path);
        Storage::disk('local')->assertExists($path);

        $logo = $this->get(route('brand.logo'));
        $logo->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($admin)->get(route('dashboard'))->assertSee(route('brand.logo'), false);

        $this->actingAs($admin)->post(route('settings.logo'), ['logo' => UploadedFile::fake()->image('new.jpg', 200, 200)])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
        $this->assertStringEndsWith('.jpg', Setting::get('logo_path'));

        $this->actingAs($admin)->delete(route('settings.logo.delete'))->assertSessionHasNoErrors();
        $this->assertNull(Setting::get('logo_path'));
        $this->get(route('brand.logo'))->assertNotFound();
    }

    public function test_logo_uploads_reject_svg_non_images_tiny_and_oversized_files(): void
    {
        $admin = User::factory()->admin()->create();

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($admin)->post(route('settings.logo'), ['logo' => $svg])->assertSessionHasErrors('logo');

        $this->actingAs($admin)->post(route('settings.logo'), ['logo' => UploadedFile::fake()->createWithContent('logo.png', 'not really a png')])->assertSessionHasErrors('logo');
        $this->actingAs($admin)->post(route('settings.logo'), ['logo' => UploadedFile::fake()->image('tiny.png', 20, 20)])->assertSessionHasErrors('logo');
        $this->actingAs($admin)->post(route('settings.logo'), ['logo' => UploadedFile::fake()->image('big.png', 400, 400)->size(2048)])->assertSessionHasErrors('logo');

        $this->assertNull(Setting::get('logo_path'));
    }

    public function test_only_admins_can_change_branding_and_workspace_settings(): void
    {
        foreach ([User::factory()->reviewer()->create(), User::factory()->viewer()->create()] as $user) {
            $this->actingAs($user)->post(route('settings.logo'), ['logo' => UploadedFile::fake()->image('l.png', 200, 200)])->assertForbidden();
            $this->actingAs($user)->post(route('settings.workspace'), ['company_name' => 'Hacked'])->assertForbidden();
        }
        $this->assertNull(Setting::get('company_name'));
    }

    public function test_workspace_settings_are_saved_and_shown_in_the_sidebar(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('settings.workspace'), [
            'company_name' => 'Northgate Trading', 'company_legal_name' => 'Northgate Trading Ltd', 'company_address' => "1 High St\nLeeds",
            'company_tax_id' => 'GB123', 'approval_threshold' => '2500', 'require_po' => '1',
        ])->assertSessionHasNoErrors();

        Setting::flush();
        $this->assertSame('Northgate Trading', Setting::get('company_name'));
        $this->assertEquals(2500.0, Setting::get('approval_threshold'));
        $this->assertTrue(Setting::get('require_po'));
        $this->actingAs($admin)->get(route('dashboard'))->assertSee('Northgate Trading');

        $this->actingAs($admin)->post(route('settings.workspace'), ['approval_threshold' => '-5'])->assertSessionHasErrors('approval_threshold');
    }

    // ------------------------------------------------------------------ Approval threshold

    public function test_reviewers_cannot_approve_above_the_threshold_but_admins_can(): void
    {
        Setting::put('approval_threshold', 1000);
        $workflow = app(DocumentWorkflow::class);
        $reviewer = User::factory()->reviewer()->create();
        $admin = User::factory()->admin()->create();
        $big = Document::factory()->create(['total' => 1500.00]);
        $small = Document::factory()->create(['total' => 999.99]);

        $this->assertTrue($workflow->needsAdminApproval($big));
        $this->assertFalse($workflow->needsAdminApproval($small));

        try {
            $workflow->approve($big, $reviewer);
            $this->fail('A reviewer must not approve above the limit');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('need an admin', $e->errors()['status'][0]);
        }
        $this->assertSame(DocumentStatus::Ready, $big->fresh()->status);

        $workflow->approve($small, $reviewer);
        $workflow->approve($big, $admin);
        $this->assertSame(DocumentStatus::Approved, $big->fresh()->status);
        $this->assertSame(DocumentStatus::Approved, $small->fresh()->status);
    }

    public function test_the_review_screen_explains_the_admin_approval_requirement(): void
    {
        Setting::put('approval_threshold', 1000);
        $document = Document::factory()->create(['total' => 1500]);

        $this->actingAs(User::factory()->reviewer()->create())->get(route('documents.show', $document))
            ->assertOk()->assertSee('Admin approval required');
        $this->actingAs(User::factory()->admin()->create())->get(route('documents.show', $document))
            ->assertOk()->assertDontSee('Admin approval required');
    }

    // ------------------------------------------------------------------ Purchase-order matching

    public function test_po_rules(): void
    {
        $this->po();
        $make = fn (array $a = []) => Document::factory()->make($a + ['po_number' => 'po 1001', 'supplier_name' => 'ACME Supplies', 'currency' => 'USD', 'total' => 400]);

        $this->assertSame([], $this->codes($make()), 'Case/punctuation-insensitive match, supplier suffix ignored');
        $this->assertSame(['po_not_found'], $this->codes($make(['po_number' => 'PO-9999'])));
        $this->assertSame(['po_supplier_mismatch'], $this->codes($make(['supplier_name' => 'Totally Different Co'])));
        $this->assertSame(['po_currency_mismatch'], $this->codes($make(['currency' => 'EUR'])));
        $this->assertSame(['po_total_exceeded'], $this->codes($make(['total' => 1000.02])));
        $this->assertSame([], $this->codes($make(['total' => 1000.00])), 'Equal to the PO value is fine');
        $this->assertSame([], $this->codes($make(['po_number' => null])));
    }

    public function test_closed_pos_and_cumulative_invoicing_are_flagged(): void
    {
        $po = $this->po();
        Document::factory()->create(['po_number' => 'PO-1001', 'supplier_name' => 'Acme Supplies Inc.', 'total' => 700]);
        $second = Document::factory()->make(['po_number' => 'PO-1001', 'supplier_name' => 'Acme Supplies Inc.', 'total' => 400]);

        $this->assertSame(['po_cumulative_exceeded'], $this->codes($second));

        Document::factory()->status(DocumentStatus::Rejected)->create(['po_number' => 'PO-1001', 'total' => 900]);
        $okay = Document::factory()->make(['po_number' => 'PO-1001', 'supplier_name' => 'Acme Supplies Inc.', 'total' => 100]);
        $this->assertSame([], $this->codes($okay), 'Rejected invoices do not count against the PO');

        $po->update(['status' => 'closed']);
        $this->assertContains('po_closed', $this->codes($okay));
    }

    public function test_require_po_setting_adds_a_warning_for_invoices_without_one(): void
    {
        $document = Document::factory()->create(['po_number' => null]);
        $this->assertSame([], $this->codes($document));

        Setting::put('require_po', true);
        $this->assertSame(['missing_po'], $this->codes($document));
    }

    public function test_adding_a_po_re_checks_open_invoices(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $document = Document::factory()->create(['po_number' => 'PO-2000', 'supplier_name' => 'Zed Parts Ltd', 'total' => 300]);
        app(DocumentProcessor::class)->revalidate($document);
        $this->assertContains('po_not_found', array_column($document->fresh()->issues(), 'code'));

        $this->actingAs($reviewer)->post(route('purchase-orders.store'), [
            'po_number' => 'PO-2000', 'supplier_name' => 'Zed Parts Ltd', 'currency' => 'USD', 'total' => '500', 'status' => 'open',
        ])->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame([], array_values(array_filter(array_column($document->issues(), 'code'), fn ($c) => str_starts_with($c, 'po_'))));
        $this->assertNotContains('po_not_found', array_column($document->issues(), 'code'));
    }

    public function test_po_register_permissions_and_validation(): void
    {
        $payload = ['po_number' => 'PO-7', 'supplier_name' => 'Acme', 'currency' => 'USD', 'total' => '10.50', 'status' => 'open'];

        $this->actingAs(User::factory()->viewer()->create())->post(route('purchase-orders.store'), $payload)->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get(route('purchase-orders.index'))->assertOk();

        $reviewer = User::factory()->reviewer()->create();
        $this->actingAs($reviewer)->post(route('purchase-orders.store'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($reviewer)->post(route('purchase-orders.store'), ['po_number' => 'po7'] + $payload)->assertSessionHasErrors('po_number');
        $this->actingAs($reviewer)->post(route('purchase-orders.store'), ['total' => '0', 'currency' => 'usd', 'po_number' => 'X'] + $payload)->assertSessionHasErrors(['total', 'currency']);

        $po = PurchaseOrder::firstOrFail();
        $this->actingAs($reviewer)->post(route('purchase-orders.toggle', $po))->assertSessionHasNoErrors();
        $this->assertSame('closed', $po->fresh()->status);

        $this->actingAs($reviewer)->delete(route('purchase-orders.destroy', $po))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->delete(route('purchase-orders.destroy', $po))->assertSessionHasNoErrors();
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_po_csv_import_creates_valid_rows_and_reports_skipped_ones(): void
    {
        $csv = "po_number,supplier_name,currency,total,issued_on\nPO-A1,Alpha Ltd,GBP,1200.00,2026-10-01\nPO-A2,Beta LLC,usd,50,2026-10-02\nPO-A1,Alpha Ltd,GBP,1,2026-10-03\nPO-A3,Gamma,EUR,-5,\nPO-A4,Delta,EUR,75,2026-13-45\n";
        $file = UploadedFile::fake()->createWithContent('pos.csv', $csv);

        $response = $this->actingAs(User::factory()->reviewer()->create())->post(route('purchase-orders.import'), ['file' => $file]);

        $response->assertSessionHas('warning');
        $this->assertSame(['PO-A1'], PurchaseOrder::pluck('po_number')->all());
        $this->assertStringContainsString('Imported 1', session('warning'));
        $this->assertStringContainsString('Skipped 4', session('warning'));
    }

    public function test_po_csv_import_requires_the_expected_columns(): void
    {
        $file = UploadedFile::fake()->createWithContent('bad.csv', "number,vendor\n1,2\n");

        $this->actingAs(User::factory()->reviewer()->create())->post(route('purchase-orders.import'), ['file' => $file])->assertSessionHasErrors('file');
        $this->assertSame(0, PurchaseOrder::count());
    }

    // ------------------------------------------------------------------ Assignment, notes

    public function test_assigning_and_filtering_by_assignee(): void
    {
        $reviewer = User::factory()->reviewer()->create(['name' => 'Rae Reviewer']);
        $viewer = User::factory()->viewer()->create();
        $mine = Document::factory()->create(['supplier_name' => 'Mine Co']);
        Document::factory()->create(['supplier_name' => 'Other Co']);

        $this->actingAs($reviewer)->post(route('documents.assign', $mine), ['assigned_to' => $reviewer->id])->assertSessionHasNoErrors();
        $this->assertSame($reviewer->id, $mine->fresh()->assigned_to);
        $this->assertContains('assigned', $mine->fresh()->events->pluck('type')->all());

        $this->actingAs($reviewer)->get(route('documents.index', ['assigned' => 'me']))->assertSee('Mine Co')->assertDontSee('Other Co');
        $this->actingAs($reviewer)->get(route('documents.index', ['assigned' => 'unassigned']))->assertSee('Other Co')->assertDontSee('Mine Co');

        $this->actingAs($reviewer)->post(route('documents.assign', $mine), ['assigned_to' => $viewer->id])->assertSessionHasErrors('assigned_to');
        $this->actingAs($reviewer)->post(route('documents.assign', $mine), ['assigned_to' => ''])->assertSessionHasNoErrors();
        $this->assertNull($mine->fresh()->assigned_to);

        $this->actingAs($viewer)->post(route('documents.assign', $mine), ['assigned_to' => $reviewer->id])->assertForbidden();
    }

    public function test_notes_can_be_added_by_reviewers_only_and_are_logged(): void
    {
        $document = Document::factory()->create();
        $reviewer = User::factory()->reviewer()->create(['name' => 'Rae Reviewer']);

        $this->actingAs($reviewer)->post(route('documents.comment', $document), ['body' => 'Checked against the delivery note.'])->assertSessionHasNoErrors();
        $this->actingAs($reviewer)->post(route('documents.comment', $document), ['body' => ''])->assertSessionHasErrors('body');
        $this->actingAs(User::factory()->viewer()->create())->post(route('documents.comment', $document), ['body' => 'Hello'])->assertForbidden();

        $this->assertSame(1, $document->comments()->count());
        $this->assertContains('commented', $document->fresh()->events->pluck('type')->all());
        $this->actingAs($reviewer)->get(route('documents.show', $document))->assertSee('Checked against the delivery note.')->assertSee('Rae Reviewer');
    }

    // ------------------------------------------------------------------ Bulk actions

    public function test_bulk_approve_skips_documents_with_errors_and_says_why(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $a = Document::factory()->create();
        $b = Document::factory()->create();
        $bad = Document::factory()->withError()->create(['invoice_number' => 'BAD-1']);

        $response = $this->actingAs($reviewer)->post(route('documents.bulk'), ['action' => 'approve', 'ids' => [$a->uuid, $b->uuid, $bad->uuid]]);

        $response->assertSessionHas('warning');
        $this->assertSame(DocumentStatus::Approved, $a->fresh()->status);
        $this->assertSame(DocumentStatus::Approved, $b->fresh()->status);
        $this->assertSame(DocumentStatus::NeedsReview, $bad->fresh()->status);
        $this->assertStringContainsString('Approved 2', session('warning'));
        $this->assertStringContainsString('BAD-1', session('warning'));
    }

    public function test_bulk_reject_needs_a_reason_and_delete_is_admin_only(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $docs = Document::factory()->count(2)->create();
        $ids = $docs->pluck('uuid')->all();

        $this->actingAs($reviewer)->post(route('documents.bulk'), ['action' => 'reject', 'ids' => $ids])->assertSessionHasErrors('reason');
        $this->actingAs($reviewer)->post(route('documents.bulk'), ['action' => 'reject', 'ids' => $ids, 'reason' => 'Duplicates'])->assertSessionHas('success');
        $this->assertSame(2, Document::where('status', DocumentStatus::Rejected->value)->count());

        $this->actingAs($reviewer)->post(route('documents.bulk'), ['action' => 'delete', 'ids' => $ids])->assertForbidden();
        $this->assertSame(2, Document::count());

        $this->actingAs(User::factory()->admin()->create())->post(route('documents.bulk'), ['action' => 'delete', 'ids' => $ids])->assertSessionHas('success');
        $this->assertSame(0, Document::count());
    }

    public function test_bulk_actions_are_closed_to_viewers_and_validate_input(): void
    {
        $doc = Document::factory()->create();

        $this->actingAs(User::factory()->viewer()->create())->post(route('documents.bulk'), ['action' => 'approve', 'ids' => [$doc->uuid]])->assertForbidden();

        $reviewer = User::factory()->reviewer()->create();
        $this->actingAs($reviewer)->post(route('documents.bulk'), ['action' => 'explode', 'ids' => [$doc->uuid]])->assertSessionHasErrors('action');
        $this->actingAs($reviewer)->post(route('documents.bulk'), ['action' => 'approve', 'ids' => ['not-a-uuid']])->assertSessionHasErrors('ids.0');
        $this->actingAs($reviewer)->post(route('documents.bulk'), ['action' => 'approve'])->assertSessionHasErrors('ids');
    }

    public function test_bulk_assign_works(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $docs = Document::factory()->count(2)->create();

        $this->actingAs($reviewer)->post(route('documents.bulk'), ['action' => 'assign', 'ids' => $docs->pluck('uuid')->all(), 'assigned_to' => $reviewer->id])->assertSessionHas('success');

        $this->assertSame(2, Document::where('assigned_to', $reviewer->id)->count());
    }

    // ------------------------------------------------------------------ Approval record PDF

    public function test_approval_record_is_a_pdf_only_for_approved_invoices(): void
    {
        $viewer = User::factory()->viewer()->create();
        $pending = Document::factory()->create();
        $approved = Document::factory()->approved()->create(['invoice_number' => 'INV-7', 'supplier_name' => 'Acme Supplies Inc.', 'po_number' => 'PO-1001', 'bank_account' => 'GB29 NWBK 6016 1331 9268 19']);
        $this->po();
        Setting::put('company_name', 'Northgate Trading');

        $this->actingAs($viewer)->get(route('documents.summary', $pending))->assertNotFound();

        $response = $this->actingAs($viewer)->get(route('documents.summary', $approved));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $path = tempnam(sys_get_temp_dir(), 'rec').'.pdf';
        file_put_contents($path, $response->baseResponse->getContent());
        $text = app(PdfTextExtractor::class)->extract($path)['text'];
        @unlink($path);

        $this->assertStringContainsString('Invoice approval record', $text);
        $this->assertStringContainsString('Northgate Trading', $text);
        $this->assertStringContainsString('INV-7', $text);
        $this->assertStringContainsString('PO-1001', $text);
        $this->assertStringContainsString('6819', $text);
        $this->assertStringNotContainsString('GB29 NWBK 6016', $text, 'The bank account is masked on the record');
        $this->assertStringContainsString('not an AI model', $text, 'Demo data is declared on the record');
    }

    public function test_approval_record_embeds_the_logo_when_one_is_set(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('settings.logo'), ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertSessionHasNoErrors();

        $document = Document::factory()->approved()->create();
        $response = $this->actingAs($admin)->get(route('documents.summary', $document));

        $response->assertOk();
        $this->assertStringContainsString('/Image', $response->baseResponse->getContent());
    }

    // ------------------------------------------------------------------ Suppliers, exports, formatting

    public function test_suppliers_page_groups_name_variants_and_searches(): void
    {
        Document::factory()->approved()->create(['supplier_name' => 'Acme Supplies Inc.', 'total' => 100, 'currency' => 'USD']);
        Document::factory()->create(['supplier_name' => 'ACME SUPPLIES, INC', 'total' => 50]);
        Document::factory()->create(['supplier_name' => 'Globex Corp', 'supplier_tax_id' => 'US-998877']);
        $user = User::factory()->viewer()->create();

        $page = $this->actingAs($user)->get(route('suppliers.index'))->assertOk();
        $this->assertSame(2, substr_count($page->getContent(), 'class="cell-main d-block text-truncate"'));

        $this->actingAs($user)->get(route('suppliers.index', ['q' => '998877']))->assertSee('Globex Corp')->assertDontSee('Acme');
    }

    public function test_exports_include_order_details_and_mask_the_bank_account(): void
    {
        Document::factory()->approved()->create([
            'po_number' => 'PO-1001', 'payment_terms' => 'Net 30 days', 'supplier_tax_id' => 'GB123', 'bill_to_name' => 'Harbourview',
            'discount' => 10, 'shipping' => 5, 'bank_account' => 'GB29 NWBK 6016 1331 9268 19',
        ]);
        $user = User::factory()->viewer()->create();

        $csv = $this->actingAs($user)->get(route('exports.csv'))->streamedContent();
        $this->assertStringContainsString('po_number', $csv);
        $this->assertStringContainsString('PO-1001', $csv);
        $this->assertStringContainsString('Net 30 days', $csv);
        $this->assertStringNotContainsString('NWBK 6016', $csv);
        $this->assertStringContainsString('6819', $csv);

        $json = $this->actingAs($user)->get(route('exports.json'))->json();
        $this->assertSame('PO-1001', $json['documents'][0]['po_number']);
        $this->assertSame('Harbourview', $json['documents'][0]['bill_to']['name']);
        $this->assertEquals(10.0, $json['documents'][0]['discount']);
        $this->assertStringNotContainsString('NWBK', json_encode($json));
    }

    public function test_selected_documents_can_be_exported_by_id(): void
    {
        $a = Document::factory()->approved()->create(['supplier_name' => 'Pick Me Ltd']);
        Document::factory()->approved()->create(['supplier_name' => 'Skip Me Ltd']);

        $csv = $this->actingAs(User::factory()->viewer()->create())->get(route('exports.csv', ['status' => 'all', 'ids' => [$a->uuid]]))->streamedContent();

        $this->assertStringContainsString('Pick Me Ltd', $csv);
        $this->assertStringNotContainsString('Skip Me Ltd', $csv);
    }

    public function test_masked_bank_account_hides_all_but_the_last_four(): void
    {
        $masked = (new Document(['bank_account' => 'US 0211 0000 2290 5518']))->maskedBankAccount();
        $this->assertStringEndsWith('5518', $masked);
        $this->assertStringNotContainsString('0211', $masked);
        $this->assertNull((new Document(['bank_account' => null]))->maskedBankAccount());
    }

    public function test_edits_to_the_new_fields_are_saved_and_validated(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $document = Document::factory()->create(['subtotal' => 100, 'tax' => 20, 'total' => 120]);

        $this->actingAs($reviewer)->put(route('documents.update', $document), [
            'supplier_name' => $document->supplier_name, 'invoice_number' => $document->invoice_number, 'invoice_date' => $document->invoice_date->toDateString(),
            'currency' => 'USD', 'subtotal' => '100', 'tax' => '20', 'total' => '112', 'discount' => '10', 'shipping' => '2',
            'po_number' => 'PO-55', 'payment_terms' => 'Net 45', 'supplier_tax_id' => 'TX-1', 'bill_to_name' => 'Buyer', 'bill_to_address' => "1 Road\nTown",
            'bank_account' => 'IBAN 123456', 'invoice_notes' => 'Thanks', 'supplier_address' => "9 Lane\nCity",
            'line_items' => [['sku' => 'S-1', 'description' => 'Thing', 'quantity' => '1', 'unit_price' => '100', 'tax_rate' => '20', 'amount' => '100']],
        ])->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame('PO-55', $document->po_number);
        $this->assertSame("1 Road\nTown", $document->bill_to_address);
        $this->assertSame('10.00', $document->discount);
        $this->assertSame('S-1', $document->lineItems->first()->sku);
        $this->assertSame('20.00', $document->lineItems->first()->tax_rate);
        $this->assertSame(0, $document->errorCount(), '100 - 10 + 2 + 20 = 112, so the total reconciles');

        $this->actingAs($reviewer)->put(route('documents.update', $document), ['line_items' => [['description' => 'x', 'tax_rate' => '250']]])->assertSessionHasErrors('line_items.0.tax_rate');
    }
}
