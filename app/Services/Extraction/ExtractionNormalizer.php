<?php

namespace App\Services\Extraction;

use Carbon\Carbon;
use Throwable;

/**
 * Turns loosely-typed provider output (strings such as "$1,234.50" or "12 Mar 2026")
 * into the canonical shape the rest of the application expects.
 */
class ExtractionNormalizer
{
    public const FIELDS = [
        'supplier_name', 'invoice_number', 'invoice_date', 'due_date',
        'currency', 'subtotal', 'tax', 'total',
        'po_number', 'supplier_address', 'supplier_tax_id', 'bill_to_name', 'bill_to_address',
        'payment_terms', 'discount', 'shipping', 'bank_account', 'invoice_notes',
    ];

    private const SYMBOL_CURRENCY = ['$' => 'USD', '£' => 'GBP', '€' => 'EUR', '¥' => 'JPY', '₹' => 'INR'];

    /**
     * @param  array<string, mixed>  $raw  {fields: {...}, line_items: [...], confidence: {...}}
     * @return array{fields:array<string,mixed>,lineItems:array<int,array<string,mixed>>,confidence:array<string,float>}
     */
    public function normalise(array $raw): array
    {
        $in = $raw['fields'] ?? $raw;
        $fields = [
            'supplier_name' => $this->text($in['supplier_name'] ?? null, 255),
            'invoice_number' => $this->text($in['invoice_number'] ?? null, 80),
            'invoice_date' => self::date($in['invoice_date'] ?? null),
            'due_date' => self::date($in['due_date'] ?? null),
            'currency' => self::currency($in['currency'] ?? null),
            'subtotal' => self::amount($in['subtotal'] ?? null),
            'tax' => self::amount($in['tax'] ?? null),
            'total' => self::amount($in['total'] ?? null),
            'po_number' => $this->text($in['po_number'] ?? null, 80),
            'supplier_address' => $this->multiline($in['supplier_address'] ?? null, 500),
            'supplier_tax_id' => $this->text($in['supplier_tax_id'] ?? null, 40),
            'bill_to_name' => $this->text($in['bill_to_name'] ?? null, 255),
            'bill_to_address' => $this->multiline($in['bill_to_address'] ?? null, 500),
            'payment_terms' => $this->text($in['payment_terms'] ?? null, 120),
            'discount' => self::amount($in['discount'] ?? null),
            'shipping' => self::amount($in['shipping'] ?? null),
            'bank_account' => $this->text($in['bank_account'] ?? null, 60),
            'invoice_notes' => $this->multiline($in['invoice_notes'] ?? null, 1000),
        ];

        $confidence = [];
        foreach (self::FIELDS as $field) {
            $score = $raw['confidence'][$field] ?? null;
            if ($fields[$field] !== null && is_numeric($score)) {
                $confidence[$field] = round(max(0.0, min(1.0, (float) $score)), 2);
            }
        }

        $lineItems = [];
        foreach (($raw['line_items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $description = $this->text($item['description'] ?? null, 255);
            if ($description === null) {
                continue;
            }
            $score = $item['confidence'] ?? null;
            $lineItems[] = [
                'description' => $description,
                'sku' => $this->text($item['sku'] ?? null, 60),
                'quantity' => self::amount($item['quantity'] ?? null),
                'unit_price' => self::amount($item['unit_price'] ?? null),
                'amount' => self::amount($item['amount'] ?? null),
                'tax_rate' => self::amount($item['tax_rate'] ?? null),
                'confidence' => is_numeric($score) ? round(max(0.0, min(1.0, (float) $score)), 2) : null,
            ];
            if (count($lineItems) >= 200) {
                break;
            }
        }

        return ['fields' => $fields, 'lineItems' => $lineItems, 'confidence' => $confidence];
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Like text() but keeps line breaks (addresses, notes). */
    private function multiline(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $lines = array_values(array_filter(array_map(
            fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l) ?? ''),
            preg_split('/\R/u', $value) ?: []
        ), fn ($l) => $l !== ''));

        return $lines ? mb_substr(implode("\n", $lines), 0, $max) : null;
    }

    /** Parses "1,234.56", "1.234,56", "$99", "(12.50)", "1 234,50" into a float. */
    public static function amount(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float) $value, 4);
        }
        if (! is_string($value)) {
            return null;
        }

        $s = trim($value);
        $negative = str_starts_with($s, '(') && str_ends_with($s, ')') || str_starts_with($s, '-');
        $s = preg_replace('/[^\d.,]/u', '', $s) ?? '';
        if ($s === '' || ! preg_match('/\d/', $s)) {
            return null;
        }

        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // The separator that appears last is the decimal separator.
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            // "12,50" -> decimal comma; "1,250" or "1,250,000" -> thousands separator.
            if (preg_match('/,\d{1,2}$/', $s) && substr_count($s, ',') === 1) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastDot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }

        if (! is_numeric($s)) {
            return null;
        }
        $number = (float) $s;

        return round($negative ? -$number : $number, 4);
    }

    /**
     * Returns Y-m-d or null. Slash dates are read as d/m/Y when the first part is above 12,
     * otherwise m/d/Y (US style). Ambiguity is a known limitation and is documented.
     */
    public static function date(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $s = trim(preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $value) ?? $value);

        try {
            if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{2,4})$#', $s, $m)) {
                [$a, $b] = [(int) $m[1], (int) $m[2]];
                $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
                [$day, $month] = $a > 12 ? [$a, $b] : [$b, $a];
                // "dd.mm.yyyy" with dots is the European convention.
                if (str_contains($s, '.')) {
                    [$day, $month] = [$a, $b];
                }
                $date = Carbon::createSafe($year, $month, $day);
            } else {
                $date = Carbon::parse($s);
            }
        } catch (Throwable) {
            return null;
        }

        if (! $date || $date->year < 2000 || $date->year > 2100) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    public static function currency(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        foreach (self::SYMBOL_CURRENCY as $symbol => $code) {
            if ($value === $symbol) {
                return $code;
            }
        }
        $code = strtoupper($value);

        return preg_match('/^[A-Z]{3}$/', $code) ? $code : null;
    }
}
