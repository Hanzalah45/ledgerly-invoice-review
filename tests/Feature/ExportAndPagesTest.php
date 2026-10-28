<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportAndPagesTest extends TestCase
{
    use RefreshDatabase;

    private function csvRows(string $content): array
    {
        $content = ltrim($content, "\xEF\xBB\xBF");
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($content))));
        $header = array_shift($rows);

        return array_map(fn ($row) => array_combine($header, $row), $rows);
    }

    public function test_guests_are_redirected_to_login_for_every_page(): void
    {
        foreach (['dashboard', 'documents.index', 'documents.create', 'exports.index', 'activity', 'settings'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->get('/')->assertRedirect();
    }

    public function test_every_page_renders_for_an_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $document = Document::factory()->create();
        DocumentEvent::create(['document_id' => $document->id, 'type' => 'uploaded', 'message' => 'Uploaded.']);

        foreach (['dashboard', 'documents.index', 'documents.create', 'exports.index', 'activity', 'settings'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
        $this->actingAs($admin)->get(route('documents.show', $document))->assertOk()->assertSee($document->supplier_name);
    }

    public function test_settings_are_admin_only(): void
    {
        $this->actingAs(User::factory()->reviewer()->create())->get(route('settings'))->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get(route('settings'))->assertForbidden();
    }

    public function test_settings_never_reveal_api_keys(): void
    {
        config(['docai.providers.openai.key' => 'sk-super-secret-1234567890']);

        $this->actingAs(User::factory()->admin()->create())->get(route('settings'))
            ->assertOk()->assertDontSee('sk-super-secret-1234567890');
    }

    public function test_login_succeeds_fails_and_logs_out(): void
    {
        $user = User::factory()->create(['email' => 'rev@example.test']);

        $this->post(route('login.attempt'), ['email' => 'rev@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.attempt'), ['email' => 'rev@example.test', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_csv_export_defaults_to_approved_documents_only(): void
    {
        Document::factory()->approved()->create(['supplier_name' => 'Approved Co', 'invoice_number' => 'A-1']);
        Document::factory()->create(['supplier_name' => 'Pending Co', 'invoice_number' => 'P-1']);
        Document::factory()->status(DocumentStatus::Rejected)->create(['supplier_name' => 'Rejected Co']);

        $response = $this->actingAs(User::factory()->viewer()->create())->get(route('exports.csv'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $rows = $this->csvRows($response->streamedContent());
        $this->assertCount(1, $rows);
        $this->assertSame('Approved Co', $rows[0]['supplier_name']);
        $this->assertSame('demo_parser', $rows[0]['source']);
    }

    public function test_exports_can_include_all_statuses_and_filter(): void
    {
        Document::factory()->approved()->create(['supplier_name' => 'Alpha Ltd']);
        Document::factory()->create(['supplier_name' => 'Beta Ltd']);
        $user = User::factory()->viewer()->create();

        $all = $this->csvRows($this->actingAs($user)->get(route('exports.csv', ['status' => 'all']))->streamedContent());
        $this->assertCount(2, $all);

        $filtered = $this->csvRows($this->actingAs($user)->get(route('exports.csv', ['status' => 'all', 'q' => 'beta']))->streamedContent());
        $this->assertCount(1, $filtered);
        $this->assertSame('Beta Ltd', $filtered[0]['supplier_name']);
    }

    public function test_csv_export_neutralises_spreadsheet_formulas(): void
    {
        Document::factory()->approved()->create(['supplier_name' => '=HYPERLINK("http://evil.test","click")', 'invoice_number' => '+CMD']);

        $rows = $this->csvRows($this->actingAs(User::factory()->viewer()->create())->get(route('exports.csv'))->streamedContent());

        $this->assertStringStartsWith("'=", $rows[0]['supplier_name']);
        $this->assertStringStartsWith("'+", $rows[0]['invoice_number']);
    }

    public function test_json_export_has_metadata_line_items_and_demo_flag(): void
    {
        $document = Document::factory()->approved()->create(['currency' => 'GBP']);
        $document->lineItems()->create(['description' => 'Haulage', 'quantity' => 2, 'unit_price' => 50, 'amount' => 100, 'position' => 0]);

        $json = $this->actingAs(User::factory()->viewer()->create())->get(route('exports.json'))->assertOk()->json();

        $this->assertSame(1, $json['meta']['count']);
        $this->assertTrue($json['meta']['contains_demo_data']);
        $this->assertSame('demo_parser', $json['documents'][0]['source']);
        $this->assertSame('GBP', $json['documents'][0]['currency']);
        $this->assertSame('Haulage', $json['documents'][0]['line_items'][0]['description']);
        $this->assertEquals(100.0, $json['documents'][0]['line_items'][0]['amount']);
    }

    public function test_exports_are_recorded_in_the_activity_log(): void
    {
        Document::factory()->approved()->create();
        $this->actingAs(User::factory()->viewer()->create())->get(route('exports.json'))->assertOk();

        $this->assertDatabaseHas('document_events', ['type' => 'exported']);
    }

    public function test_document_list_search_filters_and_sorting(): void
    {
        Document::factory()->create(['supplier_name' => 'Zeta Freight', 'invoice_number' => 'Z-1', 'total' => 10]);
        Document::factory()->withError()->create(['supplier_name' => 'Alpha Supplies', 'invoice_number' => 'A-1', 'total' => 500]);
        Document::factory()->approved()->create(['supplier_name' => 'Mid Co', 'invoice_number' => 'M-1', 'total' => 100]);
        $user = User::factory()->viewer()->create();

        $this->actingAs($user)->get(route('documents.index', ['q' => 'zeta']))->assertSee('Zeta Freight')->assertDontSee('Alpha Supplies');
        $this->actingAs($user)->get(route('documents.index', ['status' => 'approved']))->assertSee('Mid Co')->assertDontSee('Zeta Freight');
        $this->actingAs($user)->get(route('documents.index', ['issues' => 'errors']))->assertSee('Alpha Supplies')->assertDontSee('Mid Co');
        $this->actingAs($user)->get(route('documents.index', ['sort' => 'total', 'dir' => 'asc']))
            ->assertSeeInOrder(['Zeta Freight', 'Mid Co', 'Alpha Supplies']);
        $this->actingAs($user)->get(route('documents.index', ['sort' => 'DROP TABLE', 'dir' => 'sideways']))->assertOk();
    }

    public function test_dashboard_counts_reflect_the_data(): void
    {
        Document::factory()->count(2)->approved()->create();
        Document::factory()->withError()->create();
        Document::factory()->status(DocumentStatus::Failed)->create();

        $this->actingAs(User::factory()->viewer()->create())->get(route('dashboard'))
            ->assertOk()->assertSee('Awaiting review')->assertSee('1 failed');
    }

    public function test_unknown_documents_return_404(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/documents/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }
}
