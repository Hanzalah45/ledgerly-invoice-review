<?php

namespace App\Console\Commands;

use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionManager;
use App\Services\Extraction\PdfTextExtractor;
use App\Services\Samples\SampleInvoices;
use App\Services\Validation\DocumentValidator;
use Illuminate\Console\Command;

/**
 * Sends one synthetic sample invoice to the configured provider and shows what came back.
 * No real document and no database record is involved.
 */
class CheckProvider extends Command
{
    protected $signature = 'docai:check {--provider= : demo, openai or anthropic (defaults to DOCAI_PROVIDER)}';

    protected $description = 'Test the configured extraction provider with a synthetic sample invoice.';

    public function handle(ExtractionManager $providers, PdfTextExtractor $reader): int
    {
        $provider = $providers->provider($this->option('provider') ?: null);

        $this->line("Provider: <info>{$provider->name()}</info>".($provider->model() ? " (model {$provider->model()})" : ''));
        if (! $provider->isConfigured()) {
            $this->error("No API key is configured for {$provider->name()}. Run: php artisan docai:set-key {$provider->name()}");

            return self::FAILURE;
        }

        $definition = collect(SampleInvoices::definitions())->firstWhere('file', 'atlas-office-supplies-AOS-77231.pdf');
        $path = tempnam(sys_get_temp_dir(), 'docai').'.pdf';
        file_put_contents($path, SampleInvoices::render($definition));

        try {
            $text = $reader->extract($path)['text'];
            $this->line('Sending a synthetic sample invoice ('.mb_strlen($text).' characters of text)…');
            $result = $provider->extract($text, 'sample.pdf');
        } catch (ExtractionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($path);
        }

        $this->newLine();
        $this->line($result->isDemo ? '<comment>Output came from the demo rule parser, not an AI model.</comment>' : '<info>Output came from a live AI model.</info>');
        $expected = ['invoice_number' => 'AOS-77231', 'total' => 1543.83, 'currency' => 'USD', 'po_number' => 'PO-77001'];
        $rows = [];
        $hits = 0;
        foreach ($expected as $field => $want) {
            $got = $result->fields[$field] ?? null;
            $ok = is_float($want) ? is_numeric($got) && abs((float) $got - $want) < 0.01 : strtoupper((string) $got) === strtoupper((string) $want);
            $hits += $ok ? 1 : 0;
            $rows[] = [$field, (string) $want, (string) ($got ?? 'null'), $ok ? 'ok' : 'differs'];
        }
        $this->table(['Field', 'Expected', 'Extracted', ''], $rows);
        $this->line('Supplier: '.($result->fields['supplier_name'] ?? 'null').' · line items: '.count($result->lineItems));

        $issues = DocumentValidator::fromConfig()->validate($result->fields, $result->lineItems, $result->confidence);
        $errors = count(array_filter($issues, fn ($i) => $i['severity'] === 'error'));
        $this->line("Validation: {$errors} error(s), ".(count($issues) - $errors).' warning(s).');

        if ($hits === count($expected) && $errors === 0) {
            $this->info('The provider works end to end.');

            return self::SUCCESS;
        }
        $this->warn('The call succeeded but some values differ from the known sample. Review the table above.');

        return self::SUCCESS;
    }
}
