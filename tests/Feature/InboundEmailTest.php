<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\Samples\SampleInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InboundEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['docai.provider' => 'demo', 'docai.inbound.token' => 'inbound-secret', 'docai.inbound.allowed_domains' => '']);
        User::factory()->admin()->create();
    }

    private function pdf(string $file = 'atlas-office-supplies-AOS-77231.pdf'): string
    {
        return SampleInvoices::render(collect(SampleInvoices::definitions())->firstWhere('file', $file));
    }

    private function send(array $payload, string $token = 'inbound-secret')
    {
        return $this->postJson('/api/inbound/email', $payload, ['Authorization' => 'Bearer '.$token]);
    }

    private function postmark(array $attachments, array $override = []): array
    {
        return $override + ['MessageID' => 'pm-1', 'From' => 'Ana Supplier <billing@atlas-supplies.example>', 'Subject' => 'Invoice AOS-77231', 'Attachments' => $attachments];
    }

    public function test_the_endpoint_is_off_until_a_token_is_configured(): void
    {
        config(['docai.inbound.token' => null]);

        $this->postJson('/api/inbound/email', [])->assertNotFound();
    }

    public function test_a_wrong_or_missing_token_is_refused(): void
    {
        $this->send($this->postmark([]), 'nope')->assertUnauthorized();
        $this->postJson('/api/inbound/email', $this->postmark([]))->assertUnauthorized();
        $this->assertSame(0, Document::count());
    }

    public function test_a_postmark_style_email_becomes_a_processed_invoice(): void
    {
        $this->send($this->postmark([['Name' => 'AOS-77231.pdf', 'Content' => base64_encode($this->pdf()), 'ContentType' => 'application/pdf']]))
            ->assertCreated()->assertJsonCount(1, 'documents');

        $doc = Document::firstOrFail();
        $this->assertSame(['email', 'billing@atlas-supplies.example'], [$doc->channel, $doc->inbound_from]);
        $this->assertSame('AOS-77231.pdf', $doc->original_name);
        $this->assertSame('AOS-77231', $doc->invoice_number, 'It was extracted like any upload');
        $this->assertNotSame(DocumentStatus::Uploaded, $doc->status);
        $this->assertStringContainsString('Received by email from billing@atlas-supplies.example', $doc->events->last()->message);
    }

    public function test_the_generic_shape_works_too(): void
    {
        $this->send(['message_id' => 'g-1', 'from' => 'ap@vendor.example', 'subject' => 'Inv', 'attachments' => [['filename' => 'a.pdf', 'content_base64' => base64_encode($this->pdf())]]])->assertCreated();

        $this->assertSame('ap@vendor.example', Document::firstOrFail()->inbound_from);
    }

    public function test_only_pdfs_are_imported_and_the_rest_are_reported(): void
    {
        $response = $this->send($this->postmark([
            ['Name' => 'logo.png', 'Content' => base64_encode("\x89PNG\r\n"), 'ContentType' => 'image/png'],
            ['Name' => 'fake.pdf', 'Content' => base64_encode('<?php echo 1;'), 'ContentType' => 'application/pdf'],
            ['Name' => 'good.pdf', 'Content' => base64_encode($this->pdf()), 'ContentType' => 'application/pdf'],
            ['Name' => 'broken', 'Content' => '###not base64###'],
        ]))->assertCreated();

        $this->assertSame(1, Document::count());
        $this->assertCount(3, $response->json('skipped'));
        $this->assertSame('not a PDF', $response->json('skipped.0.reason'));
    }

    public function test_an_email_without_a_pdf_is_a_422(): void
    {
        $this->send($this->postmark([['Name' => 'a.txt', 'Content' => base64_encode('hello')]]))->assertUnprocessable()->assertJsonPath('error', 'no_pdf_attachment');
        $this->send($this->postmark([]))->assertUnprocessable();
    }

    public function test_redelivery_of_the_same_message_creates_nothing_new(): void
    {
        $payload = $this->postmark([['Name' => 'a.pdf', 'Content' => base64_encode($this->pdf())]]);

        $this->send($payload)->assertCreated();
        $this->send($payload)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, Document::count());
    }

    public function test_two_attachments_in_one_message_become_two_invoices_with_distinct_ids(): void
    {
        $this->send($this->postmark([
            ['Name' => 'a.pdf', 'Content' => base64_encode($this->pdf())],
            ['Name' => 'b.pdf', 'Content' => base64_encode($this->pdf('northwind-logistics-INV-2026-0412.pdf'))],
        ]))->assertCreated()->assertJsonCount(2, 'documents');

        $this->assertSame(['pm-1#1', 'pm-1#2'], Document::orderBy('id')->pluck('inbound_message_id')->all());
    }

    public function test_only_allowed_sender_domains_are_accepted_when_a_list_is_set(): void
    {
        config(['docai.inbound.allowed_domains' => 'atlas-supplies.example, northwind.example']);
        $attachment = [['Name' => 'a.pdf', 'Content' => base64_encode($this->pdf())]];

        $this->send($this->postmark($attachment, ['From' => 'x@evil.example']))->assertForbidden()->assertJsonPath('error', 'sender_not_allowed');
        $this->send($this->postmark($attachment, ['MessageID' => 'pm-ok', 'From' => 'Ops <ops@NORTHWIND.example>']))->assertCreated();
    }

    public function test_oversized_attachments_are_skipped(): void
    {
        config(['docai.max_upload_kb' => 1]);

        $this->send($this->postmark([['Name' => 'big.pdf', 'Content' => base64_encode($this->pdf())]]))->assertUnprocessable()->assertJsonPath('skipped.0.reason', 'too large');
    }

    public function test_the_configured_inbox_user_owns_the_invoice_and_an_admin_is_the_fallback(): void
    {
        $inbox = User::factory()->reviewer()->create(['email' => 'inbox@ledgerly.test']);
        $this->send($this->postmark([['Name' => 'a.pdf', 'Content' => base64_encode($this->pdf())]]))->assertCreated();
        $this->assertSame($inbox->id, Document::firstOrFail()->user_id);

        $inbox->delete();
        $this->send($this->postmark([['Name' => 'b.pdf', 'Content' => base64_encode($this->pdf())]], ['MessageID' => 'pm-2']))->assertCreated();
        $this->assertSame(User::where('role', 'admin')->value('id'), Document::latest('id')->firstOrFail()->user_id);
    }

    public function test_emailed_invoices_carry_their_channel_into_the_export(): void
    {
        $this->send($this->postmark([['Name' => 'a.pdf', 'Content' => base64_encode($this->pdf())]]))->assertCreated();

        $csv = $this->actingAs(User::where('role', 'admin')->first())->get(route('exports.csv', ['status' => 'all']))->streamedContent();

        $this->assertStringContainsString('received_via', $csv);
        $this->assertStringContainsString(',email', $csv);
    }
}
