<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use App\Models\User;
use App\Services\Samples\SampleInvoices;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['docai.provider' => 'demo']);
        Setting::flush();
        PurchaseOrder::create(['po_number' => 'PO-77001', 'supplier_name' => 'Atlas Office Supplies Inc.', 'currency' => 'USD', 'total' => 1600, 'status' => 'open']);
        PurchaseOrder::create(['po_number' => 'PO-PA-1188', 'supplier_name' => 'Bluepeak Software Pty Ltd', 'currency' => 'AUD', 'total' => 9000, 'status' => 'open']);
    }

    private function pdf(string $file): UploadedFile
    {
        $definition = collect(SampleInvoices::definitions())->firstWhere('file', $file);

        return UploadedFile::fake()->createWithContent($file, SampleInvoices::render($definition));
    }

    private function upload(User $user, string $file): Document
    {
        $this->actingAs($user)->post(route('documents.store'), ['files' => [$this->pdf($file)]])->assertRedirect();

        return Document::latest('id')->firstOrFail();
    }

    public function test_uploading_a_clean_invoice_extracts_validates_and_marks_it_ready(): void
    {
        $document = $this->upload(User::factory()->reviewer()->create(), 'atlas-office-supplies-AOS-77231.pdf');

        $this->assertSame(DocumentStatus::Ready, $document->status);
        $this->assertTrue($document->is_demo);
        $this->assertSame('AOS-77231', $document->invoice_number);
        $this->assertSame('1543.83', $document->total);
        $this->assertSame(4, $document->lineItems()->count());
        $this->assertSame(0, $document->errorCount());
        $this->assertSame(64, strlen($document->sha256));
        Storage::disk('local')->assertExists($document->stored_path);
        $this->assertEqualsCanonicalizing(['uploaded', 'processing_started', 'extracted', 'validated'], $document->events->pluck('type')->unique()->all());
    }

    public function test_a_flawed_invoice_needs_review_and_cannot_be_approved(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $document = $this->upload($reviewer, 'FLAWED-totals-mismatch-bluepeak-BP-9904.pdf');

        $this->assertSame(DocumentStatus::NeedsReview, $document->status);
        $this->assertSame(1, $document->errorCount());
        $this->assertSame('total_mismatch', $document->issues()[0]['code']);

        $this->actingAs($reviewer)->post(route('documents.approve', $document))->assertSessionHasErrors('status');
        $this->assertSame(DocumentStatus::NeedsReview, $document->fresh()->status);
    }

    public function test_correcting_the_total_clears_the_error_and_allows_approval(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $document = $this->upload($reviewer, 'FLAWED-totals-mismatch-bluepeak-BP-9904.pdf');

        $this->actingAs($reviewer)->put(route('documents.update', $document), [
            'supplier_name' => $document->supplier_name,
            'invoice_number' => $document->invoice_number,
            'invoice_date' => '2026-10-14',
            'due_date' => '2026-11-13',
            'currency' => 'AUD',
            'subtotal' => '7350.00',
            'tax' => '735.00',
            'total' => '8,085.00',
            'line_items' => $document->lineItems->map(fn ($i) => ['description' => $i->description, 'quantity' => $i->quantity, 'unit_price' => $i->unit_price, 'amount' => $i->amount])->all(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame('8085.00', $document->total);
        $this->assertTrue($document->human_edited);
        $this->assertSame(1.0, $document->fieldConfidence('total'), 'A reviewer-confirmed value is marked verified');
        $this->assertSame(0, $document->errorCount());
        $this->assertSame(DocumentStatus::Ready, $document->status);
        $this->assertContains('edited', $document->events->pluck('type')->all());

        $this->actingAs($reviewer)->post(route('documents.approve', $document))->assertSessionHasNoErrors();
        $document->refresh();
        $this->assertSame(DocumentStatus::Approved, $document->status);
        $this->assertSame($reviewer->id, $document->reviewed_by);
    }

    public function test_invalid_edits_are_rejected_with_field_errors(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $document = Document::factory()->create();

        $this->actingAs($reviewer)->put(route('documents.update', $document), [
            'invoice_date' => 'yesterday', 'currency' => 'dollars', 'total' => 'abc',
        ])->assertSessionHasErrors(['invoice_date', 'currency', 'total']);
    }

    public function test_approved_documents_are_locked_until_reopened(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $document = Document::factory()->approved()->create();

        $this->actingAs($reviewer)->put(route('documents.update', $document), ['supplier_name' => 'Changed'])->assertSessionHasErrors('status');
        $this->assertNotSame('Changed', $document->fresh()->supplier_name);

        $this->actingAs($reviewer)->post(route('documents.reopen', $document))->assertSessionHasNoErrors();
        $this->assertTrue($document->fresh()->status->isEditable());
    }

    public function test_rejecting_requires_a_reason_and_records_it(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $document = Document::factory()->create();

        $this->actingAs($reviewer)->post(route('documents.reject', $document), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($reviewer)->post(route('documents.reject', $document), ['reason' => 'Wrong entity'])->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame(DocumentStatus::Rejected, $document->status);
        $this->assertSame('Wrong entity', $document->rejection_reason);
    }

    public function test_reprocessing_replaces_manual_edits(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $document = $this->upload($reviewer, 'atlas-office-supplies-AOS-77231.pdf');
        $document->update(['supplier_name' => 'Edited by hand', 'human_edited' => true]);

        $this->actingAs($reviewer)->post(route('documents.reprocess', $document))->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame('Atlas Office Supplies Inc.', $document->supplier_name);
        $this->assertFalse($document->human_edited);
        $this->assertContains('reprocessed', $document->events->pluck('type')->all());
    }

    public function test_image_only_pdfs_fail_with_a_clear_message(): void
    {
        $document = $this->upload(User::factory()->reviewer()->create(), 'SCANNED-image-only-receipt.pdf');

        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertStringContainsString('scanned image', $document->error_message);
        $this->assertSame(0, $document->errorCount());
    }

    public function test_duplicate_invoices_are_flagged_as_errors(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $first = $this->upload($reviewer, 'northwind-logistics-INV-2026-0412.pdf');
        $second = $this->upload($reviewer, 'DUPLICATE-northwind-logistics-INV-2026-0412.pdf');

        $this->assertSame(0, $first->fresh()->errorCount());
        $this->assertContains('duplicate_invoice', array_column($second->issues(), 'code'));
        $this->assertSame(DocumentStatus::NeedsReview, $second->status);
    }

    public function test_only_admins_can_delete_and_the_file_is_removed(): void
    {
        $reviewer = User::factory()->reviewer()->create();
        $admin = User::factory()->admin()->create();
        $document = $this->upload($reviewer, 'atlas-office-supplies-AOS-77231.pdf');
        $path = $document->stored_path;

        $this->actingAs($reviewer)->delete(route('documents.destroy', $document))->assertForbidden();
        $this->assertDatabaseHas('documents', ['id' => $document->id]);

        $this->actingAs($admin)->delete(route('documents.destroy', $document))->assertRedirect(route('documents.index'));
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_viewers_are_read_only(): void
    {
        $viewer = User::factory()->viewer()->create();
        $document = Document::factory()->create();

        $this->actingAs($viewer)->get(route('documents.show', $document))->assertOk();
        $this->actingAs($viewer)->get(route('documents.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('documents.store'), ['files' => [$this->pdf('atlas-office-supplies-AOS-77231.pdf')]])->assertForbidden();
        $this->actingAs($viewer)->put(route('documents.update', $document), ['supplier_name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->post(route('documents.approve', $document))->assertForbidden();
        $this->actingAs($viewer)->post(route('documents.reject', $document), ['reason' => 'No'])->assertForbidden();
        $this->actingAs($viewer)->post(route('documents.reprocess', $document))->assertForbidden();
        $this->actingAs($viewer)->delete(route('documents.destroy', $document))->assertForbidden();
    }
}
