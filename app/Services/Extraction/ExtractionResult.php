<?php

namespace App\Services\Extraction;

/**
 * Canonical output of every extraction provider.
 *
 * fields:      supplier_name, invoice_number, invoice_date (Y-m-d), due_date (Y-m-d),
 *              currency (ISO 4217), subtotal, tax, total (floats)  - any may be null
 * lineItems:   list of [description, quantity, unit_price, amount, confidence]
 * confidence:  field => 0..1
 */
final class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array<string, float>  $confidence
     * @param  array<string, mixed>|null  $raw
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $lineItems,
        public readonly array $confidence,
        public readonly string $provider,
        public readonly bool $isDemo,
        public readonly ?string $model = null,
        public readonly ?array $raw = null,
    ) {}
}
