<?php

namespace App\Services\Validation;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\PurchaseOrder;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Business-rule validation for extracted invoice data.
 *
 * Every issue is {field, severity: error|warning, code, message}. Errors block approval;
 * warnings are shown to the reviewer but do not.
 */
class DocumentValidator
{
    public const REQUIRED = [
        'supplier_name' => 'Supplier name',
        'invoice_number' => 'Invoice number',
        'invoice_date' => 'Invoice date',
        'total' => 'Total',
    ];

    public function __construct(
        private readonly float $tolerance = 0.01,
        private readonly float $lowConfidence = 0.70,
        private readonly array $currencies = [],
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (float) config('docai.amount_tolerance'),
            (float) config('docai.low_confidence'),
            (array) config('docai.currencies'),
        );
    }

    /**
     * Pure validation of the data itself (no database access).
     *
     * @param  array<string,mixed>  $fields
     * @param  array<int,array<string,mixed>>  $lineItems
     * @param  array<string,float|int|null>  $confidence
     * @return array<int,array{field:?string,severity:string,code:string,message:string}>
     */
    public function validate(array $fields, array $lineItems = [], array $confidence = [], ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $issues = [];
        $add = function (?string $field, string $severity, string $code, string $message) use (&$issues) {
            $issues[] = compact('field', 'severity', 'code', 'message');
        };

        // Required fields
        foreach (self::REQUIRED as $field => $label) {
            if ($this->blank($fields[$field] ?? null)) {
                $add($field, 'error', 'missing_field', "{$label} is missing.");
            }
        }
        if ($this->blank($fields['currency'] ?? null)) {
            $add('currency', 'warning', 'missing_currency', 'Currency could not be determined.');
        }
        if ($this->blank($fields['due_date'] ?? null)) {
            $add('due_date', 'warning', 'missing_due_date', 'Due date is missing.');
        }

        // Formats
        $number = (string) ($fields['invoice_number'] ?? '');
        if ($number !== '' && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\/_. #]{0,38}$/', $number)) {
            $add('invoice_number', 'warning', 'invoice_number_format', 'Invoice number contains unusual characters.');
        }
        $currency = (string) ($fields['currency'] ?? '');
        if ($currency !== '') {
            if (! preg_match('/^[A-Z]{3}$/', $currency)) {
                $add('currency', 'error', 'currency_format', 'Currency must be a 3-letter ISO code such as USD.');
            } elseif ($this->currencies && ! in_array($currency, $this->currencies, true)) {
                $add('currency', 'warning', 'unknown_currency', "{$currency} is not in the list of supported currencies.");
            }
        }

        // Dates
        $invoiceDate = $this->date($fields['invoice_date'] ?? null);
        $dueDate = $this->date($fields['due_date'] ?? null);
        if ($invoiceDate && $dueDate && $dueDate->lt($invoiceDate)) {
            $add('due_date', 'error', 'due_before_invoice', 'Due date is earlier than the invoice date.');
        }
        if ($invoiceDate && $invoiceDate->gt($today->copy()->addDay())) {
            $add('invoice_date', 'warning', 'future_invoice_date', 'Invoice date is in the future.');
        }
        if ($invoiceDate && $invoiceDate->lt($today->copy()->subYears(2))) {
            $add('invoice_date', 'warning', 'old_invoice_date', 'Invoice date is more than two years old.');
        }

        // Amounts
        $subtotal = $this->num($fields['subtotal'] ?? null);
        $tax = $this->num($fields['tax'] ?? null);
        $total = $this->num($fields['total'] ?? null);

        foreach (['subtotal' => $subtotal, 'tax' => $tax, 'total' => $total] as $field => $value) {
            if ($value !== null && $value < 0) {
                $add($field, 'warning', 'negative_amount', ucfirst($field).' is negative. Confirm this is a credit note.');
            }
        }

        $discount = $this->num($fields['discount'] ?? null);
        $shipping = $this->num($fields['shipping'] ?? null);
        foreach (['discount' => $discount, 'shipping' => $shipping] as $field => $value) {
            if ($value !== null && $value < 0) {
                $add($field, 'warning', 'negative_amount', ucfirst($field).' is negative. Enter discounts as positive numbers.');
            }
        }

        if ($subtotal !== null && $tax !== null && $total !== null) {
            $expected = round($subtotal - ($discount ?? 0) + ($shipping ?? 0) + $tax, 2);
            if (abs($expected - $total) > $this->tolerance) {
                $formula = ($discount || $shipping) ? 'subtotal − discount + shipping + tax' : 'subtotal + tax';
                $add('total', 'error', 'total_mismatch', sprintf(
                    'Total %s does not equal %s (%s). Difference: %s.',
                    $this->money($total), $formula, $this->money($expected), $this->money(abs($expected - $total))
                ));
            }
        } elseif ($subtotal !== null && $total !== null && $tax === null && $total + $this->tolerance < $subtotal) {
            $add('total', 'error', 'total_below_subtotal', 'Total is lower than the subtotal and no tax was found.');
        }

        if ($subtotal !== null && $subtotal > 0 && $tax !== null && $tax / $subtotal > 0.30) {
            $add('tax', 'warning', 'tax_rate_high', sprintf('Tax is %.0f%% of the subtotal, which is unusually high.', $tax / $subtotal * 100));
        }

        // Line items
        if (! $lineItems) {
            $add(null, 'warning', 'no_line_items', 'No line items were extracted.');
        } else {
            $sum = 0.0;
            $complete = true;
            foreach ($lineItems as $i => $item) {
                $amount = $this->num($item['amount'] ?? null);
                $qty = $this->num($item['quantity'] ?? null);
                $unit = $this->num($item['unit_price'] ?? null);
                if ($amount === null) {
                    $complete = false;
                } else {
                    $sum += $amount;
                }
                if ($amount !== null && $qty !== null && $unit !== null && abs(round($qty * $unit, 2) - $amount) > max(0.02, $this->tolerance)) {
                    $add('line_items.'.$i, 'warning', 'line_calculation', sprintf(
                        'Line %d: %s × %s = %s, but the amount is %s.',
                        $i + 1, $this->trim($qty), $this->money($unit), $this->money(round($qty * $unit, 2)), $this->money($amount)
                    ));
                }
            }

            if ($complete) {
                $reference = $subtotal ?? ($total !== null ? $total - ($tax ?? 0) - ($shipping ?? 0) + ($discount ?? 0) : null);
                if ($reference !== null && abs(round($sum, 2) - round($reference, 2)) > $this->tolerance) {
                    $add('subtotal', 'error', 'line_items_mismatch', sprintf(
                        'Line items add up to %s but the %s is %s.',
                        $this->money(round($sum, 2)), $subtotal !== null ? 'subtotal' : 'total before tax', $this->money($reference)
                    ));
                }
            }
        }

        // Low-confidence extractions are surfaced for the reviewer.
        foreach (array_merge(array_keys(self::REQUIRED), ['currency']) as $field) {
            $score = $confidence[$field] ?? null;
            if (is_numeric($score) && (float) $score < $this->lowConfidence && ! $this->blank($fields[$field] ?? null)) {
                $add($field, 'warning', 'low_confidence', sprintf('%s was extracted with low confidence (%d%%). Please verify.', ucfirst(str_replace('_', ' ', $field)), round((float) $score * 100)));
            }
        }

        return $issues;
    }

    /**
     * Duplicate checks against other documents in the database.
     *
     * @return array<int,array{field:?string,severity:string,code:string,message:string}>
     */
    public function duplicateIssues(Document $document): array
    {
        $issues = [];

        if (filled($document->invoice_number) && filled($document->supplier_name)) {
            $duplicate = Document::query()
                ->whereKeyNot($document->getKey())
                ->whereNotIn('status', [DocumentStatus::Rejected->value, DocumentStatus::Failed->value])
                ->whereRaw('LOWER(supplier_name) = ?', [mb_strtolower($document->supplier_name)])
                ->whereRaw('LOWER(invoice_number) = ?', [mb_strtolower($document->invoice_number)])
                ->first();
            if ($duplicate) {
                $issues[] = [
                    'field' => 'invoice_number',
                    'severity' => 'error',
                    'code' => 'duplicate_invoice',
                    'message' => "Invoice {$document->invoice_number} from {$document->supplier_name} already exists ({$duplicate->original_name}).",
                ];
            }
        }

        if (filled($document->sha256)) {
            $sameFile = Document::query()
                ->whereKeyNot($document->getKey())
                ->where('sha256', $document->sha256)
                ->whereNotIn('status', [DocumentStatus::Rejected->value, DocumentStatus::Failed->value])
                ->exists();
            if ($sameFile) {
                $issues[] = [
                    'field' => null,
                    'severity' => 'warning',
                    'code' => 'duplicate_file',
                    'message' => 'An identical file has already been uploaded.',
                ];
            }
        }

        return $issues;
    }

    /** Validation for a stored document (data rules + duplicates). */
    public function validateDocument(Document $document): array
    {
        $document->loadMissing('lineItems');

        $fields = [
            'supplier_name' => $document->supplier_name,
            'invoice_number' => $document->invoice_number,
            'invoice_date' => $document->invoice_date?->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'currency' => $document->currency,
            'subtotal' => $document->subtotal,
            'tax' => $document->tax,
            'total' => $document->total,
            'discount' => $document->discount,
            'shipping' => $document->shipping,
        ];
        $items = $document->lineItems->map(fn ($i) => [
            'description' => $i->description,
            'quantity' => $i->quantity,
            'unit_price' => $i->unit_price,
            'amount' => $i->amount,
        ])->all();

        return array_merge(
            $this->validate($fields, $items, $document->confidence ?? []),
            $this->scanIssues($document),
            $this->purchaseOrderIssues($document),
            $this->duplicateIssues($document),
        );
    }

    /** A value read visually from a scan can be a misread digit that the arithmetic checks do not catch. */
    public function scanIssues(Document $document): array
    {
        if (($document->raw_response['input'] ?? null) !== 'scanned PDF sent as a file' || $document->human_edited) {
            return [];
        }

        return [[
            'field' => 'total',
            'severity' => 'warning',
            'code' => 'read_from_scan',
            'message' => 'Read from a scanned PDF by a vision model. Compare every figure, date and number with the PDF before approving.',
        ]];
    }

    /**
     * Purchase-order checks: PO on file, supplier and currency agree, and the invoice (plus other invoices
     * already raised against the same PO) does not exceed the PO value. Amounts are compared as entered; no FX conversion.
     *
     * @return array<int,array{field:?string,severity:string,code:string,message:string}>
     */
    public function purchaseOrderIssues(Document $document): array
    {
        $issues = [];
        $add = function (string $severity, string $code, string $message) use (&$issues) {
            $issues[] = ['field' => 'po_number', 'severity' => $severity, 'code' => $code, 'message' => $message];
        };

        if (blank($document->po_number)) {
            if (Setting::get('require_po')) {
                $add('warning', 'missing_po', 'No purchase order number was found. Your workspace asks for one on every invoice.');
            }

            return $issues;
        }

        $po = PurchaseOrder::findByNumber($document->po_number);
        if (! $po) {
            $add('warning', 'po_not_found', "Purchase order {$document->po_number} is not on file.");

            return $issues;
        }

        if (! $po->isOpen()) {
            $add('warning', 'po_closed', "Purchase order {$po->po_number} is closed.");
        }
        if ($document->supplier_name && ! $this->sameSupplier($document->supplier_name, $po->supplier_name)) {
            $add('warning', 'po_supplier_mismatch', "PO {$po->po_number} was issued to {$po->supplier_name}, not {$document->supplier_name}.");
        }
        if ($document->currency && $po->currency !== $document->currency) {
            $add('warning', 'po_currency_mismatch', "PO {$po->po_number} is in {$po->currency} but the invoice is in {$document->currency}.");
        }

        if ($document->total !== null) {
            $total = (float) $document->total;
            $poTotal = (float) $po->total;
            if ($total > $poTotal + $this->tolerance) {
                $add('error', 'po_total_exceeded', sprintf('Invoice total %s exceeds the value of PO %s (%s).', $this->money($total), $po->po_number, $this->money($poTotal)));
            } else {
                $others = Document::query()
                    ->whereKeyNot($document->getKey())
                    ->whereNotIn('status', [DocumentStatus::Rejected->value, DocumentStatus::Failed->value])
                    ->whereNotNull('po_number')
                    ->get(['id', 'po_number', 'total'])
                    ->filter(fn ($d) => PurchaseOrder::normalise($d->po_number) === PurchaseOrder::normalise($po->po_number))
                    ->sum(fn ($d) => (float) $d->total);
                if ($others > 0 && $others + $total > $poTotal + $this->tolerance) {
                    $add('error', 'po_cumulative_exceeded', sprintf(
                        'Other invoices already total %s against PO %s. Adding this one (%s) exceeds the PO value of %s.',
                        $this->money($others), $po->po_number, $this->money($total), $this->money($poTotal)
                    ));
                }
            }
        }

        return $issues;
    }

    private function sameSupplier(string $a, string $b): bool
    {
        $clean = fn (string $s) => trim(preg_replace('/\b(inc|llc|ltd|limited|gmbh|co|corp|company|plc|llp|pty|ag)\b\.?/i', '', mb_strtolower(preg_replace('/[^\p{L}\p{N} ]/u', ' ', $s))) ?? '');
        [$x, $y] = [preg_replace('/\s+/', ' ', $clean($a)), preg_replace('/\s+/', ' ', $clean($b))];
        if ($x === '' || $y === '') {
            return true;
        }
        if ($x === $y || str_contains($x, $y) || str_contains($y, $x)) {
            return true;
        }
        similar_text($x, $y, $percent);

        return $percent >= 80;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function date(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return Carbon::parse(substr($value, 0, 10))->startOfDay();
        }

        return null;
    }

    private function money(float $value): string
    {
        return number_format($value, 2);
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
