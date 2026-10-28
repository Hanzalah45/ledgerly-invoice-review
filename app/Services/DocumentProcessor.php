<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionManager;
use App\Services\Extraction\ExtractionResult;
use App\Services\Extraction\NoTextLayerException;
use App\Services\Extraction\PdfTextExtractor;
use App\Services\Validation\DocumentValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs one document through: read PDF text (or, for a scan, send the file to a live provider)
 * -> extract -> store -> validate -> set status.
 */
class DocumentProcessor
{
    public function __construct(
        private readonly PdfTextExtractor $pdf,
        private readonly ExtractionManager $providers,
        private readonly DocumentValidator $validator,
    ) {}

    public function process(Document $document): Document
    {
        $document->update(['status' => DocumentStatus::Processing, 'error_message' => null]);
        $document->record('processing_started', 'Processing started.');

        try {
            $path = Storage::disk('local')->path($document->stored_path);
            if (! is_file($path)) {
                throw new ExtractionException('The stored file is missing from disk.');
            }

            $requested = $this->providers->provider();
            $provider = $this->providers->active();
            if ($requested->name() !== $provider->name()) {
                $document->record('processing_started', "Provider {$requested->name()} is not configured, so the demo parser was used.");
            }

            try {
                $read = $this->pdf->extract($path);
                $result = $provider->extract($read['text'], $document->original_name);
                $text = $read['text'];
                $pages = $read['pages'];
            } catch (NoTextLayerException $e) {
                if (! $provider->supportsScans()) {
                    throw new ExtractionException($e->getMessage().' Reading scans needs a live AI provider (OpenAI or Anthropic); the demo parser reads text only.');
                }

                $document->record('processing_started', "No text layer found. Sending the scanned PDF to {$provider->name()} to read it visually.");
                $result = $provider->extractFromFile($path, $document->original_name);
                $text = '(Scanned document: no text layer. Values were read visually by '.$provider->name().'.)';
                $pages = $e->pages;
            }

            $this->store($document, $result, $text, $pages);
        } catch (ExtractionException $e) {
            $this->fail($document, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Document processing crashed', ['document' => $document->uuid, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $this->fail($document, 'Unexpected error while processing this document. It has been logged.');
        }

        return $document->refresh();
    }

    private function store(Document $document, ExtractionResult $result, string $text, int $pages): void
    {
        DB::transaction(function () use ($document, $result, $text, $pages) {
            $fields = $result->fields;

            $document->fill([
                'supplier_name' => $fields['supplier_name'],
                'invoice_number' => $fields['invoice_number'],
                'invoice_date' => $fields['invoice_date'],
                'due_date' => $fields['due_date'],
                'currency' => $fields['currency'],
                'subtotal' => $fields['subtotal'],
                'tax' => $fields['tax'],
                'total' => $fields['total'],
                'po_number' => $fields['po_number'] ?? null,
                'supplier_address' => $fields['supplier_address'] ?? null,
                'supplier_tax_id' => $fields['supplier_tax_id'] ?? null,
                'bill_to_name' => $fields['bill_to_name'] ?? null,
                'bill_to_address' => $fields['bill_to_address'] ?? null,
                'payment_terms' => $fields['payment_terms'] ?? null,
                'discount' => $fields['discount'] ?? null,
                'shipping' => $fields['shipping'] ?? null,
                'bank_account' => $fields['bank_account'] ?? null,
                'invoice_notes' => $fields['invoice_notes'] ?? null,
                'confidence' => $result->confidence,
                'provider' => $result->provider,
                'model' => $result->model,
                'is_demo' => $result->isDemo,
                'raw_response' => $result->raw,
                'extracted_text' => $text,
                'page_count' => $pages,
                'human_edited' => false,
                'processed_at' => now(),
            ])->save();

            $document->lineItems()->delete();
            foreach ($result->lineItems as $position => $item) {
                $document->lineItems()->create([
                    'position' => $position,
                    'sku' => $item['sku'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'amount' => $item['amount'],
                    'tax_rate' => $item['tax_rate'] ?? null,
                    'confidence' => $item['confidence'],
                ]);
            }

            $document->record(
                'extracted',
                $result->isDemo
                    ? sprintf('Fields extracted by the demo rule parser (not AI): %d line items.', count($result->lineItems))
                    : sprintf('Fields extracted by %s (%s): %d line items.', $result->provider, $result->model, count($result->lineItems)),
                ['provider' => $result->provider, 'model' => $result->model, 'demo' => $result->isDemo],
            );
        });

        $this->revalidate($document);
    }

    /** Recalculates validation issues and moves the document to the matching review status. */
    public function revalidate(Document $document): Document
    {
        $document->load('lineItems');
        $issues = $this->validator->validateDocument($document);

        $status = match (true) {
            $document->status->isFinal() => $document->status,
            $issues !== [] => DocumentStatus::NeedsReview,
            default => DocumentStatus::Ready,
        };

        $errors = count(array_filter($issues, fn ($i) => $i['severity'] === 'error'));
        $warnings = count($issues) - $errors;

        $document->update([
            'validation_issues' => $issues,
            'error_count' => $errors,
            'warning_count' => $warnings,
            'status' => $status,
        ]);
        $document->record(
            'validated',
            $issues === []
                ? 'Validation passed with no issues.'
                : sprintf('Validation found %d error(s) and %d warning(s).', $errors, $warnings),
            ['errors' => $errors, 'warnings' => $warnings],
        );

        return $document;
    }

    private function fail(Document $document, string $message): void
    {
        $document->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $message,
            'validation_issues' => null,
            'error_count' => 0,
            'warning_count' => 0,
            'processed_at' => now(),
        ]);
        $document->record('failed', $message);
    }
}
