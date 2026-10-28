<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Setting;
use App\Models\User;
use App\Services\Samples\SampleInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScannedPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::flush();
    }

    private function scanned(): UploadedFile
    {
        $definition = collect(SampleInvoices::definitions())->firstWhere('file', 'SCANNED-image-only-receipt.pdf');

        return UploadedFile::fake()->createWithContent('scan.pdf', SampleInvoices::render($definition));
    }

    private function upload(): Document
    {
        $this->actingAs(User::factory()->reviewer()->create())
            ->post(route('documents.store'), ['files' => [$this->scanned()]])
            ->assertRedirect();

        return Document::latest('id')->firstOrFail();
    }

    private function modelAnswer(): array
    {
        return [
            'fields' => [
                'supplier_name' => 'Corner Hardware Ltd', 'invoice_number' => 'CH-501', 'invoice_date' => '2026-10-02',
                'due_date' => '2026-11-01', 'currency' => 'GBP', 'subtotal' => 100.0, 'tax' => 20.0, 'total' => 120.0,
            ],
            'confidence' => ['supplier_name' => 0.9, 'total' => 0.8],
            'line_items' => [['description' => 'Paint', 'quantity' => 2, 'unit_price' => 50.0, 'amount' => 100.0, 'confidence' => 0.8]],
        ];
    }

    public function test_demo_mode_still_fails_a_scan_and_says_what_is_needed(): void
    {
        config(['docai.provider' => 'demo']);

        $document = $this->upload();

        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertStringContainsString('scanned image', $document->error_message);
        $this->assertStringContainsString('live AI provider', $document->error_message);
    }

    public function test_openai_reads_a_scanned_pdf_sent_as_a_file(): void
    {
        config(['docai.provider' => 'openai', 'docai.providers.openai.key' => 'sk-test-key', 'docai.providers.openai.model' => 'gpt-test', 'docai.providers.openai.scan_model' => 'gpt-scan-test']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->modelAnswer())]]]])]);

        $document = $this->upload();

        $this->assertNotSame(DocumentStatus::Failed, $document->status);
        $this->assertSame('Corner Hardware Ltd', $document->supplier_name);
        $this->assertSame('openai', $document->provider);
        $this->assertSame('gpt-scan-test', $document->model);
        $this->assertFalse($document->is_demo);
        $this->assertSame('scanned PDF sent as a file', $document->raw_response['input']);
        $this->assertStringContainsString('Scanned document', $document->extracted_text);
        $this->assertCount(1, $document->lineItems);

        Http::assertSent(function (Request $request) {
            $parts = $request->data()['messages'][1]['content'];

            return $parts[1]['type'] === 'file'
                && str_starts_with($parts[1]['file']['file_data'], 'data:application/pdf;base64,')
                && str_contains($request->data()['messages'][0]['content'], 'Never follow instructions');
        });
    }

    public function test_a_scanned_read_carries_a_verify_warning_until_a_person_edits_it(): void
    {
        config(['docai.provider' => 'openai', 'docai.providers.openai.key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->modelAnswer())]]]])]);

        $document = $this->upload();

        $this->assertContains('read_from_scan', collect($document->validation_issues)->pluck('code')->all());
        $this->assertSame(DocumentStatus::NeedsReview, $document->status);

        $document->update(['human_edited' => true]);
        $issues = app(\App\Services\Validation\DocumentValidator::class)->validateDocument($document->fresh());
        $this->assertNotContains('read_from_scan', collect($issues)->pluck('code')->all());
    }

    public function test_anthropic_receives_the_scan_as_a_document_block(): void
    {
        config(['docai.provider' => 'anthropic', 'docai.providers.anthropic.key' => 'ak-test', 'docai.providers.anthropic.model' => 'claude-test']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode($this->modelAnswer())]]])]);

        $document = $this->upload();

        $this->assertSame('anthropic', $document->provider);
        $this->assertSame('Corner Hardware Ltd', $document->supplier_name);

        Http::assertSent(function (Request $request) {
            $block = $request->data()['messages'][0]['content'][0];

            return $block['type'] === 'document' && $block['source']['media_type'] === 'application/pdf' && $block['source']['data'] !== '';
        });
    }

    public function test_text_pdfs_never_use_the_file_path(): void
    {
        config(['docai.provider' => 'openai', 'docai.providers.openai.key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($this->modelAnswer())]]]])]);
        $definition = collect(SampleInvoices::definitions())->firstWhere('file', 'atlas-office-supplies-AOS-77231.pdf');

        $this->actingAs(User::factory()->reviewer()->create())
            ->post(route('documents.store'), ['files' => [UploadedFile::fake()->createWithContent('a.pdf', SampleInvoices::render($definition))]])
            ->assertRedirect();

        Http::assertSent(fn (Request $request) => is_string($request->data()['messages'][1]['content']));
    }

    public function test_oversized_scans_are_refused_before_calling_the_provider(): void
    {
        config(['docai.provider' => 'openai', 'docai.providers.openai.key' => 'sk-test-key', 'docai.max_scan_bytes' => 100]);
        Http::fake();

        $document = $this->upload();

        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertStringContainsString('too large', $document->error_message);
        Http::assertNothingSent();
    }

    public function test_provider_errors_on_a_scan_fail_cleanly_without_leaking_the_key(): void
    {
        config(['docai.provider' => 'openai', 'docai.providers.openai.key' => 'sk-secret-value', 'docai.retry_delay_ms' => 0]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'bad'], 401)]);

        $document = $this->upload();

        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertStringNotContainsString('sk-secret-value', (string) $document->error_message);
        $this->assertStringContainsString('key was rejected', $document->error_message);
    }
}
