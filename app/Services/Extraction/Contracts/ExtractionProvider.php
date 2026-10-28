<?php

namespace App\Services\Extraction\Contracts;

use App\Services\Extraction\ExtractionResult;

interface ExtractionProvider
{
    /** Machine name stored on the document: demo | openai | anthropic. */
    public function name(): string;

    /** True when output is produced by local rules instead of an AI model. */
    public function isDemo(): bool;

    /** True when the provider has everything it needs (e.g. an API key). */
    public function isConfigured(): bool;

    public function model(): ?string;

    /**
     * @throws \App\Services\Extraction\ExtractionException
     */
    public function extract(string $text, string $filename): ExtractionResult;

    /** True when the provider can read a scanned PDF itself (vision models). The demo parser cannot. */
    public function supportsScans(): bool;

    /**
     * Reads a PDF that has no text layer by sending the file to the model.
     *
     * @throws \App\Services\Extraction\ExtractionException
     */
    public function extractFromFile(string $absolutePath, string $filename): ExtractionResult;
}
