<?php

namespace App\Services\Extraction;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads the text layer of a PDF. Scanned/image-only PDFs have no text layer and are
 * reported with NoTextLayerException; a live vision-capable provider can then read the file itself.
 */
class PdfTextExtractor
{
    /**
     * @return array{text:string,pages:int}
     *
     * @throws ExtractionException
     */
    public function extract(string $absolutePath): array
    {
        try {
            $pdf = (new Parser)->parseFile($absolutePath);
            $text = $pdf->getText();
            $pages = count($pdf->getPages());
        } catch (Throwable $e) {
            throw new ExtractionException('The PDF could not be read: '.$e->getMessage(), 0, $e);
        }

        $text = $this->normalise($text);

        if (mb_strlen(preg_replace('/\s+/u', '', $text) ?? '') < 20) {
            throw new NoTextLayerException($pages);
        }

        return ['text' => $text, 'pages' => $pages];
    }

    public function normalise(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace("/\t+/", "\t", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
