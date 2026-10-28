<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $documentId) {}

    public function handle(DocumentProcessor $processor): void
    {
        $document = Document::find($this->documentId);
        if ($document) {
            $processor->process($document);
        }
    }
}
