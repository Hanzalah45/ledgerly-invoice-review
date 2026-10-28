<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderCommandsTest extends TestCase
{
    private function envFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_set_key_writes_the_key_and_provider_without_echoing_the_key(): void
    {
        $file = $this->envFile("APP_NAME=Ledgerly\nDOCAI_PROVIDER=demo\nDOCAI_OPENAI_API_KEY=\nOTHER=1\n");
        $key = 'sk-test-'.str_repeat('a', 30);

        $this->artisan('docai:set-key', ['provider' => 'openai', '--env-file' => $file])
            ->expectsQuestion('Paste your OpenAI API key (input is hidden)', $key)
            ->doesntExpectOutputToContain($key)
            ->assertSuccessful();

        $env = file_get_contents($file);
        $this->assertStringContainsString("DOCAI_OPENAI_API_KEY={$key}", $env);
        $this->assertStringContainsString('DOCAI_PROVIDER=openai', $env);
        $this->assertStringContainsString('APP_NAME=Ledgerly', $env);
        $this->assertStringContainsString('OTHER=1', $env);
        $this->assertSame(1, substr_count($env, 'DOCAI_PROVIDER='), 'Existing lines are replaced, not duplicated');
        @unlink($file);
    }

    public function test_set_key_appends_missing_variables(): void
    {
        $file = $this->envFile("APP_NAME=Ledgerly\n");

        $this->artisan('docai:set-key', ['provider' => 'anthropic', '--env-file' => $file])
            ->expectsQuestion('Paste your Anthropic API key (input is hidden)', 'sk-ant-'.str_repeat('b', 30))
            ->assertSuccessful();

        $env = file_get_contents($file);
        $this->assertStringContainsString('DOCAI_ANTHROPIC_API_KEY=sk-ant-', $env);
        $this->assertStringContainsString('DOCAI_PROVIDER=anthropic', $env);
        @unlink($file);
    }

    public function test_set_key_rejects_junk_and_unknown_providers(): void
    {
        $file = $this->envFile("DOCAI_PROVIDER=demo\n");

        $this->artisan('docai:set-key', ['provider' => 'openai', '--env-file' => $file])
            ->expectsQuestion('Paste your OpenAI API key (input is hidden)', 'short key')
            ->assertFailed();
        $this->assertSame("DOCAI_PROVIDER=demo\n", file_get_contents($file), 'Nothing is saved for an invalid key');

        $this->artisan('docai:set-key', ['provider' => 'gemini', '--env-file' => $file])->assertFailed();
        @unlink($file);
    }

    public function test_check_refuses_to_run_without_a_key(): void
    {
        config(['docai.providers.openai.key' => null]);

        $this->artisan('docai:check', ['--provider' => 'openai'])
            ->expectsOutputToContain('No API key is configured')
            ->assertFailed();
    }

    public function test_check_runs_the_sample_through_a_live_provider_and_never_prints_the_key(): void
    {
        config(['docai.providers.openai.key' => 'sk-secret-check-key-123456', 'docai.providers.openai.model' => 'gpt-test']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'fields' => ['supplier_name' => 'Atlas Office Supplies Inc.', 'invoice_number' => 'AOS-77231', 'po_number' => 'PO-77001', 'invoice_date' => '2026-09-08', 'due_date' => '2026-10-08', 'currency' => 'USD', 'subtotal' => 1405.47, 'shipping' => 24, 'tax' => 114.36, 'total' => 1543.83],
            'confidence' => ['total' => 0.99],
            'line_items' => [['description' => 'A4 copy paper', 'quantity' => 12, 'unit_price' => 38.5, 'amount' => 462, 'confidence' => 0.9], ['description' => 'Toner', 'quantity' => 3, 'unit_price' => 119.99, 'amount' => 359.97], ['description' => 'Chair', 'quantity' => 2, 'unit_price' => 249, 'amount' => 498], ['description' => 'Markers', 'quantity' => 6, 'unit_price' => 14.25, 'amount' => 85.5]],
        ])]]]])]);

        $this->artisan('docai:check', ['--provider' => 'openai'])
            ->expectsOutputToContain('live AI model')
            ->expectsOutputToContain('works end to end')
            ->doesntExpectOutputToContain('sk-secret-check-key')
            ->assertSuccessful();
    }

    public function test_check_reports_provider_errors_safely(): void
    {
        config(['docai.providers.openai.key' => 'sk-secret-check-key-123456', 'docai.retry_delay_ms' => 0]);
        Http::fake(['api.openai.com/*' => Http::response(['error' => 'bad key'], 401)]);

        $this->artisan('docai:check', ['--provider' => 'openai'])
            ->expectsOutputToContain('API key was rejected')
            ->doesntExpectOutputToContain('sk-secret-check-key')
            ->assertFailed();
    }

    public function test_check_works_in_demo_mode_and_says_it_is_not_ai(): void
    {
        $this->artisan('docai:check', ['--provider' => 'demo'])
            ->expectsOutputToContain('not an AI model')
            ->assertSuccessful();
    }
}
