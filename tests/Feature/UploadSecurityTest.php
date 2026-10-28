<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentWorkflow;
use App\Services\Samples\SampleInvoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['docai.provider' => 'demo']);
    }

    private function validPdf(string $name = 'ok.pdf'): UploadedFile
    {
        $definition = SampleInvoices::definitions()[1];

        return UploadedFile::fake()->createWithContent($name, SampleInvoices::render($definition));
    }

    public function test_non_pdf_files_are_rejected(): void
    {
        $user = User::factory()->reviewer()->create();

        $this->actingAs($user)->post(route('documents.store'), ['files' => [UploadedFile::fake()->image('photo.png')]])
            ->assertSessionHasErrors('files.0');
        $this->actingAs($user)->post(route('documents.store'), ['files' => [UploadedFile::fake()->createWithContent('notes.txt', 'plain text')]])
            ->assertSessionHasErrors('files.0');

        $this->assertSame(0, Document::count());
    }

    public function test_a_binary_renamed_to_pdf_is_rejected(): void
    {
        $user = User::factory()->reviewer()->create();

        // A real temp file (not Laravel's fake) so the request validation sniffs the actual bytes.
        $path = tempnam(sys_get_temp_dir(), 'evil');
        file_put_contents($path, 'MZ'.str_repeat("\0", 64).'pretend executable');
        $php = new UploadedFile($path, 'invoice.pdf', 'application/pdf', null, true);

        $this->actingAs($user)->post(route('documents.store'), ['files' => [$php]])->assertSessionHasErrors('files.0');
        $this->assertSame(0, Document::count());
        @unlink($path);
    }

    public function test_the_workflow_itself_checks_the_pdf_signature(): void
    {
        $user = User::factory()->reviewer()->create();
        $fake = UploadedFile::fake()->createWithContent('invoice.pdf', 'definitely not a pdf');

        $this->expectException(ValidationException::class);
        app(DocumentWorkflow::class)->upload($fake, $user);
    }

    public function test_oversized_files_are_rejected(): void
    {
        config(['docai.max_upload_kb' => 50]);
        $user = User::factory()->reviewer()->create();

        $this->actingAs($user)->post(route('documents.store'), ['files' => [$this->validPdf()]])->assertSessionHasErrors('files.0');
    }

    public function test_an_upload_needs_at_least_one_file_and_at_most_the_configured_limit(): void
    {
        $user = User::factory()->reviewer()->create();

        $this->actingAs($user)->post(route('documents.store'), [])->assertSessionHasErrors('files');

        $files = array_map(fn ($i) => $this->validPdf("f{$i}.pdf"), range(1, config('docai.max_files_per_upload') + 1));
        $this->actingAs($user)->post(route('documents.store'), ['files' => $files])->assertSessionHasErrors('files');
    }

    public function test_files_are_stored_privately_under_random_names(): void
    {
        $user = User::factory()->reviewer()->create();
        $this->actingAs($user)->post(route('documents.store'), ['files' => [$this->validPdf('../../evil name.pdf')]]);

        $document = Document::firstOrFail();
        $this->assertMatchesRegularExpression('#^documents/[0-9a-f-]{36}\.pdf$#', $document->stored_path);
        $this->assertStringNotContainsString('..', $document->original_name);
        $this->assertStringNotContainsString('/', $document->original_name);
        Storage::disk('local')->assertExists($document->stored_path);
    }

    public function test_the_pdf_endpoint_requires_authentication_and_serves_inline_pdf(): void
    {
        $user = User::factory()->reviewer()->create();
        $this->actingAs($user)->post(route('documents.store'), ['files' => [$this->validPdf()]]);
        $document = Document::firstOrFail();

        auth()->logout();
        $this->get(route('documents.file', $document))->assertRedirect(route('login'));

        $response = $this->actingAs($user)->get(route('documents.file', $document));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_sample_downloads_only_serve_whitelisted_pdf_names(): void
    {
        $user = User::factory()->viewer()->create();

        $this->actingAs($user)->get('/samples/..%2F..%2F.env')->assertNotFound();
        $this->actingAs($user)->get('/samples/not-there.pdf')->assertNotFound();
        $this->actingAs($user)->get('/samples/script.php')->assertNotFound();
    }
}
