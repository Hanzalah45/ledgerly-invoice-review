<?php

namespace App\Services\Extraction\Providers;

use App\Services\Extraction\Contracts\ExtractionProvider;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\ExtractionNormalizer;
use App\Services\Extraction\ExtractionResult;

/**
 * Demo extraction: deterministic regular-expression rules over the PDF text layer.
 *
 * This is NOT an AI model. Documents processed here are stored with is_demo = true and are
 * labelled "Demo parser" everywhere in the UI and in exports. The confidence values are
 * rule-match heuristics (labelled match = high, positional guess = low), not model scores.
 */
class DemoRuleProvider implements ExtractionProvider
{
    private const DATE_RE = '(?:\d{4}-\d{2}-\d{2}|\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}\.?,?\s+\d{4}|[A-Za-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})';

    public function __construct(private readonly ExtractionNormalizer $normalizer = new ExtractionNormalizer) {}

    public function name(): string
    {
        return 'demo';
    }

    public function isDemo(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsScans(): bool
    {
        return false;
    }

    public function extractFromFile(string $absolutePath, string $filename): ExtractionResult
    {
        throw new ExtractionException('The demo parser reads text only. Connect OpenAI or Anthropic to read scanned documents.');
    }

    public function model(): ?string
    {
        return 'rules-v1';
    }

    public function extract(string $text, string $filename): ExtractionResult
    {
        $lines = $this->lines($text);

        $raw = ['fields' => [], 'confidence' => [], 'line_items' => []];

        [$raw['fields']['invoice_number'], $raw['confidence']['invoice_number']] = $this->invoiceNumber($text, $lines);
        [$raw['fields']['invoice_date'], $raw['confidence']['invoice_date']] = $this->labelledDate($lines, 'invoice');
        [$raw['fields']['due_date'], $raw['confidence']['due_date']] = $this->labelledDate($lines, 'due');
        [$raw['fields']['supplier_name'], $raw['confidence']['supplier_name']] = $this->supplier($lines);

        $totals = $this->totals($lines);
        foreach (['subtotal', 'tax', 'total'] as $key) {
            [$raw['fields'][$key], $raw['confidence'][$key]] = $totals[$key];
        }

        [$raw['fields']['currency'], $raw['confidence']['currency']] = $this->currency($text, $lines);
        $raw['line_items'] = $this->lineItems($lines);

        foreach (['discount', 'shipping'] as $key) {
            [$raw['fields'][$key], $raw['confidence'][$key]] = $totals[$key];
        }
        foreach ($this->details($lines, (string) ($raw['fields']['supplier_name'] ?? '')) as $key => [$value, $score]) {
            $raw['fields'][$key] = $value;
            $raw['confidence'][$key] = $score;
        }

        $normalised = $this->normalizer->normalise($raw);

        return new ExtractionResult(
            fields: $normalised['fields'],
            lineItems: $normalised['lineItems'],
            confidence: $normalised['confidence'],
            provider: $this->name(),
            isDemo: true,
            model: $this->model(),
            raw: ['parser' => 'rules-v1', 'note' => 'Produced by regular expressions, not an AI model.', 'filename' => $filename],
        );
    }

    /** @return array<int,string> */
    private function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            $line = trim(preg_replace('/[ ]{2,}/', ' ', $line) ?? $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return array{0:?string,1:float} */
    private function invoiceNumber(string $text, array $lines): array
    {
        $label = '/(?:invoice\s*(?:no\.?|number|num\.?|#|id)|inv\s*(?:no\.?|#)|bill\s*no\.?|document\s*no\.?)\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-\/_.]{2,30})/i';
        foreach ($lines as $i => $line) {
            if (preg_match($label, $line, $m) && ! preg_match('/^(date|total|due)$/i', $m[1])) {
                return [rtrim($m[1], '.'), 0.93];
            }
            // Label on one line, value on the next ("Invoice number" / "INV-1043").
            if (preg_match('/^(?:invoice\s*(?:no\.?|number|#)|inv\s*no\.?)\s*:?$/i', $line) && isset($lines[$i + 1])
                && preg_match('/^([A-Z0-9][A-Z0-9\-\/_.]{2,30})$/i', $lines[$i + 1], $m)) {
                return [$m[1], 0.9];
            }
        }
        if (preg_match('/\b([A-Z]{2,5}-\d{3,}(?:-\d+)*)\b/', $text, $m)) {
            return [$m[1], 0.55];
        }

        return [null, 0.0];
    }

    /** @return array{0:?string,1:float} */
    private function labelledDate(array $lines, string $kind): array
    {
        $labels = $kind === 'due'
            ? '/(?:payment\s*)?due(?:\s*date|\s*on|\s*by)?|pay\s*by/i'
            : '/(?:invoice\s*date|date\s*of\s*issue|issue\s*date|issued|billing\s*date|^date\b)/i';

        foreach ($lines as $i => $line) {
            $isDueLine = (bool) preg_match('/due|pay\s*by/i', $line);
            if (($kind === 'due') !== $isDueLine && ! ($kind === 'invoice' && preg_match('/invoice\s*date|issue\s*date|date\s*of\s*issue/i', $line))) {
                continue;
            }
            if (! preg_match($labels, $line)) {
                continue;
            }
            if (preg_match('/'.self::DATE_RE.'/i', $line, $m)) {
                $parsed = ExtractionNormalizer::date($m[0]);
                if ($parsed) {
                    return [$parsed, 0.92];
                }
            }
            // Value on the following line.
            if (isset($lines[$i + 1]) && preg_match('/^'.self::DATE_RE.'$/i', $lines[$i + 1], $m)) {
                $parsed = ExtractionNormalizer::date($m[0]);
                if ($parsed) {
                    return [$parsed, 0.88];
                }
            }
        }

        return [null, 0.0];
    }

    /** @return array{0:?string,1:float} */
    private function supplier(array $lines): array
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/^(?:from|supplier|vendor|seller|bill(?:ed)?\s*from|issued\s*by)\s*[:\-]?\s*(.*)$/i', $line, $m)) {
                $value = trim($m[1]) !== '' ? trim($m[1]) : ($lines[$i + 1] ?? '');
                if ($value !== '') {
                    return [$value, 0.9];
                }
            }
        }

        $company = '/(?<![A-Za-z])(ltd|limited|llc|inc|incorporated|gmbh|corp|corporation|co|company|plc|pvt|llp|s\.a|b\.v|pty|ag)\.?(?![A-Za-z])/i';
        foreach (array_slice($lines, 0, 8) as $line) {
            if (preg_match($company, $line) && ! preg_match('/invoice|bill to|ship to/i', $line)) {
                return [$line, 0.8];
            }
        }

        foreach (array_slice($lines, 0, 4) as $line) {
            if (! preg_match('/^(tax\s*)?invoice|^page|^date|^\d|receipt|^bill to/i', $line) && mb_strlen($line) <= 60) {
                return [$line, 0.5];
            }
        }

        return [null, 0.0];
    }

    /** @return array{subtotal:array{0:?string,1:float},tax:array{0:?string,1:float},total:array{0:?string,1:float}} */
    private function totals(array $lines): array
    {
        $result = ['subtotal' => [null, 0.0], 'tax' => [null, 0.0], 'total' => [null, 0.0], 'discount' => [null, 0.0], 'shipping' => [null, 0.0]];
        $totalCandidates = [];

        foreach ($lines as $i => $line) {
            $clean = trim(preg_replace('/\(?\d+(?:[.,]\d+)?\s*%\)?/', '', $line) ?? $line);
            // Totals rows are "label <tab> amount". A line item such as "Delivery and setup  1  $85.00  $85.00"
            // has more columns and must not be mistaken for the shipping charge.
            $isTotalsRow = count(explode("\t", $line)) <= 2 && preg_match_all('/\d[\d.,]*/', $clean) <= 2;
            if ($isTotalsRow && preg_match('/^discount\b/i', $clean)) {
                $amount = $this->lastAmount($clean) ?? $this->lastAmount($lines[$i + 1] ?? '');
                if ($amount !== null) {
                    $result['discount'] = [ltrim($amount, '-'), 0.88];
                }
            } elseif ($isTotalsRow && preg_match('/^(?:shipping|freight|delivery(?:\s*charge)?|postage)\b/i', $clean) && ! preg_match('/\bdescription\b/i', $clean)) {
                $amount = $this->lastAmount($clean) ?? $this->lastAmount($lines[$i + 1] ?? '');
                if ($amount !== null) {
                    $result['shipping'] = [$amount, 0.86];
                }
            } elseif (preg_match('/^(?:sub\s*-?\s*total|net\s*(?:amount|total))\b/i', $clean)) {
                $amount = $this->lastAmount($clean) ?? $this->lastAmount($lines[$i + 1] ?? '');
                if ($amount !== null) {
                    $result['subtotal'] = [$amount, 0.9];
                }
            } elseif (preg_match('/^(?:vat|gst|hst|sales\s*tax|tax)\b/i', $clean) && ! preg_match('/invoice/i', $clean)) {
                $amount = $this->lastAmount($clean) ?? $this->lastAmount($lines[$i + 1] ?? '');
                if ($amount !== null) {
                    $result['tax'] = [$amount, 0.88];
                }
            } elseif (preg_match('/^(grand\s*total|total\s*due|amount\s*due|balance\s*due|total\s*amount|invoice\s*total|total)\b/i', $clean, $m)) {
                $amount = $this->lastAmount($clean) ?? $this->lastAmount($lines[$i + 1] ?? '');
                if ($amount !== null) {
                    $priority = preg_match('/^(grand|total\s*due|amount\s*due|balance|invoice\s*total)/i', $m[1]) ? 2 : 1;
                    $totalCandidates[] = [$priority, $amount];
                }
            }
        }

        if ($totalCandidates) {
            usort($totalCandidates, fn ($a, $b) => $b[0] <=> $a[0]);
            $result['total'] = [$totalCandidates[0][1], $totalCandidates[0][0] === 2 ? 0.93 : 0.85];
        }

        return $result;
    }

    private function lastAmount(string $line): ?string
    {
        if ($line === '') {
            return null;
        }
        $line = preg_replace('/\(?\d+(?:[.,]\d+)?\s*%\)?/', '', $line) ?? $line;
        if (preg_match_all('/-?\(?[$£€¥₹]?\s?\d[\d.,]*\)?/u', $line, $m) && $m[0]) {
            $token = end($m[0]);
            if (ExtractionNormalizer::amount($token) !== null) {
                return $token;
            }
        }

        return null;
    }

    /** @return array{0:?string,1:float} */
    private function currency(string $text, array $lines): array
    {
        $allowed = implode('|', config('docai.currencies'));
        if (preg_match('/\bcurrency\s*[:\-]?\s*('.$allowed.')\b/i', $text, $m)) {
            return [strtoupper($m[1]), 0.95];
        }
        if (preg_match('/\b('.$allowed.')\b/', $text, $m)) {
            return [$m[1], 0.85];
        }
        foreach (['£' => 'GBP', '€' => 'EUR', '$' => 'USD'] as $symbol => $code) {
            if (str_contains($text, $symbol)) {
                return [$code, 0.65];
            }
        }

        return [null, 0.0];
    }

    /**
     * Order and billing details: PO number, addresses, tax ID, payment terms, bank account, notes.
     *
     * @return array<string,array{0:?string,1:float}>
     */
    private function details(array $lines, string $supplier): array
    {
        $out = [];
        $value = fn (string $line, string $label) => trim((string) preg_replace('/^'.$label.'\s*[:#\-]?\s*/i', '', $line));

        $headerEnd = count($lines);
        foreach ($lines as $i => $line) {
            if (preg_match('/^(tax\s*)?invoice$/i', $line)) {
                $headerEnd = $i;
                break;
            }
        }

        foreach ($lines as $i => $line) {
            if (! isset($out['po_number']) && preg_match('/^(?:purchase\s*order|po)\s*(?:no\.?|number|#|ref(?:erence)?)?\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-\/_.]{2,30})$/i', $line, $m) && ! preg_match('/^(box)$/i', $m[1])) {
                $out['po_number'] = [$m[1], 0.9];
            } elseif (! isset($out['po_number']) && preg_match('/^(?:order\s*(?:no\.?|number|ref(?:erence)?)|your\s*ref(?:erence)?)\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-\/_.]{2,30})$/i', $line, $m)) {
                $out['po_number'] = [$m[1], 0.75];
            }
            if (! isset($out['supplier_tax_id']) && preg_match('/^(?:vat|gst|tax|abn|ein|tin|ntn|trn)\s*(?:id|no\.?|number|reg(?:istration)?(?:\s*no\.?)?)?\s*[:#\-]\s*([A-Z0-9][A-Z0-9 \-\/.]{4,25})$/i', $line, $m)) {
                $out['supplier_tax_id'] = [trim($m[1]), 0.88];
            }
            if (! isset($out['payment_terms']) && preg_match('/^payment\s*terms\s*[:\-]?\s*(.+?)(?:\.\s|$)/i', $line, $m)) {
                $out['payment_terms'] = [trim($m[1]), 0.88];
            } elseif (! isset($out['payment_terms']) && preg_match('/\bpayment\s*terms\s*[:\-]\s*([^.]{3,60})/i', $line, $m)) {
                $out['payment_terms'] = [trim($m[1]), 0.8];
            }
            if (! isset($out['bank_account']) && preg_match('/^(?:iban|bank\s*account|account\s*(?:no\.?|number)|a\/c)\s*[:#\-]?\s*([A-Z0-9][A-Z0-9 \-]{6,40})$/i', $line, $m)) {
                $out['bank_account'] = [trim($m[1]), 0.88];
            }
            if (! isset($out['invoice_notes']) && preg_match('/^notes?\s*[:\-]\s*(.+)$/i', $line, $m)) {
                $out['invoice_notes'] = [trim($m[1]), 0.82];
            }
        }

        // Supplier address: the lines directly below the supplier name, before the INVOICE heading.
        $sIndex = array_search($supplier, $lines, true);
        if ($sIndex !== false) {
            $address = [];
            for ($j = $sIndex + 1; $j < $headerEnd && count($address) < 4; $j++) {
                if (preg_match('/^(?:vat|gst|tax|abn|ein|tin)\b/i', $lines[$j]) || preg_match('/^invoice\b/i', $lines[$j])) {
                    break;
                }
                $address[] = $lines[$j];
            }
            if ($address) {
                $out['supplier_address'] = [implode("\n", $address), 0.78];
            }
        }

        // Bill to: name and address after the "Bill to" label.
        foreach ($lines as $i => $line) {
            if (preg_match('/^bill(?:ed)?\s*to\s*:?\s*(.*)$/i', $line, $m)) {
                $name = trim($m[1]) !== '' ? trim($m[1]) : ($lines[$i + 1] ?? null);
                $skip = trim($m[1]) !== '' ? 1 : 2;
                if ($name) {
                    $out['bill_to_name'] = [$name, 0.86];
                    $address = [];
                    for ($j = $i + $skip; $j < count($lines) && count($address) < 4; $j++) {
                        if (preg_match('/\bdescription\b/i', $lines[$j]) || preg_match('/^(?:po|purchase|order|ship)/i', $lines[$j])) {
                            break;
                        }
                        $address[] = $lines[$j];
                    }
                    if ($address) {
                        $out['bill_to_address'] = [implode("\n", $address), 0.74];
                    }
                }
                break;
            }
        }

        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private function lineItems(array $lines): array
    {
        $start = null;
        $hasSku = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/\bdescription\b/i', $line) && preg_match('/\b(qty|quantity|amount|price|rate)\b/i', $line)) {
                $start = $i + 1;
                $hasSku = (bool) preg_match('/^(sku|item\s*(?:code|no\.?)|code)\b/i', $line);
                break;
            }
        }
        if ($start === null) {
            return [];
        }

        $items = [];
        for ($i = $start; $i < count($lines); $i++) {
            $line = $lines[$i];
            if (preg_match('/^(sub\s*-?\s*total|total|tax|vat|gst|amount\s*due|balance|grand\s*total|payment|notes?|bank|thank)\b/i', $line)) {
                break;
            }

            $item = $this->parseItemLine($line);
            if ($item === null && isset($lines[$i + 1])) {
                // Description wrapped onto a second line: "Consulting\nsprint 3 5 120.00 600.00".
                $item = $this->parseItemLine($line.' '.$lines[$i + 1]);
                if ($item !== null) {
                    $i++;
                }
            }
            if ($item !== null) {
                if ($hasSku && preg_match('/^(\S+)\s+(.+)$/su', (string) $item['description'], $m)) {
                    $item['sku'] = $m[1];
                    $item['description'] = $m[2];
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    /** @return array<string,mixed>|null */
    private function parseItemLine(string $line): ?array
    {
        $num = '[$£€]?\s?\d[\d.,]*';
        if (preg_match('/^(.+?)\s+('.$num.')\s+('.$num.')\s+('.$num.')$/u', $line, $m)) {
            return [
                'description' => $m[1], 'quantity' => $m[2], 'unit_price' => $m[3], 'amount' => $m[4], 'confidence' => 0.88,
            ];
        }
        if (preg_match('/^(.+?)\s+('.$num.')\s+('.$num.')$/u', $line, $m) && preg_match('/[A-Za-z]/', $m[1])) {
            return [
                'description' => $m[1], 'quantity' => $m[2], 'unit_price' => null, 'amount' => $m[3], 'confidence' => 0.6,
            ];
        }
        if (preg_match('/^(.+?)\s+('.$num.')$/u', $line, $m) && preg_match('/[A-Za-z]{3}/', $m[1])) {
            return [
                'description' => $m[1], 'quantity' => null, 'unit_price' => null, 'amount' => $m[2], 'confidence' => 0.5,
            ];
        }

        return null;
    }
}
