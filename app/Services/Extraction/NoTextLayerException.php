<?php

namespace App\Services\Extraction;

/** The PDF opened fine but has no text layer (a scan or image-only file). */
class NoTextLayerException extends ExtractionException
{
    public function __construct(public readonly int $pages = 1)
    {
        parent::__construct('No readable text was found in this PDF. It looks like a scanned image.');
    }
}
