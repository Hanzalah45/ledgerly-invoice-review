<?php

namespace Tests\Unit;

use App\Services\Validation\DocumentValidator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DocumentValidatorTest extends TestCase
{
    private function validator(): DocumentValidator
    {
        return new DocumentValidator(0.01, 0.70, ['USD', 'EUR', 'GBP', 'CAD']);
    }

    private function valid(array $override = []): array
    {
        return array_merge([
            'supplier_name' => 'Atlas Office Supplies Inc.',
            'invoice_number' => 'AOS-77231',
            'invoice_date' => '2026-09-08',
            'due_date' => '2026-10-08',
            'currency' => 'USD',
            'subtotal' => 1405.47,
            'tax' => 112.44,
            'total' => 1517.91,
        ], $override);
    }

    private function lines(): array
    {
        return [
            ['description' => 'Paper', 'quantity' => 12, 'unit_price' => 38.50, 'amount' => 462.00],
            ['description' => 'Toner', 'quantity' => 3, 'unit_price' => 119.99, 'amount' => 359.97],
            ['description' => 'Chair', 'quantity' => 2, 'unit_price' => 249.00, 'amount' => 498.00],
            ['description' => 'Markers', 'quantity' => 6, 'unit_price' => 14.25, 'amount' => 85.50],
        ];
    }

    private function codes(array $issues): array
    {
        return array_column($issues, 'code');
    }

    private function check(array $fields, array $items = null, array $confidence = []): array
    {
        return $this->validator()->validate($fields, $items ?? $this->lines(), $confidence, Carbon::parse('2026-10-28'));
    }

    public function test_a_consistent_invoice_has_no_issues(): void
    {
        $this->assertSame([], $this->check($this->valid()));
    }

    public function test_missing_required_fields_are_errors(): void
    {
        $issues = $this->check($this->valid(['supplier_name' => null, 'invoice_number' => '', 'invoice_date' => null, 'total' => null]));

        $missing = array_filter($issues, fn ($i) => $i['code'] === 'missing_field');
        $this->assertCount(4, $missing);
        $this->assertSame('error', collect($missing)->first()['severity']);
    }

    public function test_missing_currency_and_due_date_are_only_warnings(): void
    {
        $issues = $this->check($this->valid(['currency' => null, 'due_date' => null]));

        $this->assertEqualsCanonicalizing(['missing_currency', 'missing_due_date'], $this->codes($issues));
        $this->assertSame(['warning'], array_unique(array_column($issues, 'severity')));
    }

    public function test_total_must_equal_subtotal_plus_tax(): void
    {
        $issues = $this->check($this->valid(['total' => 1600.00]));

        $this->assertContains('total_mismatch', $this->codes($issues));
        $message = collect($issues)->firstWhere('code', 'total_mismatch')['message'];
        $this->assertStringContainsString('1,517.91', $message);
        $this->assertStringContainsString('Difference: 82.09', $message);
    }

    public function test_discount_and_shipping_are_part_of_the_total(): void
    {
        $ok = $this->check($this->valid(['discount' => 100.00, 'shipping' => 24.00, 'total' => 1441.91]));
        $this->assertNotContains('total_mismatch', $this->codes($ok), '1405.47 - 100 + 24 + 112.44 = 1441.91');

        $bad = $this->check($this->valid(['discount' => 100.00, 'shipping' => 24.00, 'total' => 1517.91]));
        $message = collect($bad)->firstWhere('code', 'total_mismatch')['message'];
        $this->assertStringContainsString('subtotal − discount + shipping + tax', $message);
        $this->assertStringContainsString('1,441.91', $message);
    }

    public function test_negative_discount_or_shipping_is_flagged(): void
    {
        $this->assertContains('negative_amount', $this->codes($this->check($this->valid(['discount' => -5.0, 'total' => 1523.91]))));
    }

    public function test_line_items_are_compared_to_the_pre_discount_subtotal_when_subtotal_is_missing(): void
    {
        $issues = $this->check($this->valid(['subtotal' => null, 'discount' => 100.00, 'shipping' => 24.00, 'total' => 1441.91]));

        $this->assertNotContains('line_items_mismatch', $this->codes($issues), 'total - tax - shipping + discount equals the item sum');
    }

    public function test_rounding_differences_within_tolerance_pass(): void
    {
        $this->assertNotContains('total_mismatch', $this->codes($this->check($this->valid(['total' => 1517.92]))));
        $this->assertContains('total_mismatch', $this->codes($this->check($this->valid(['total' => 1517.95]))));
    }

    public function test_line_items_must_sum_to_the_subtotal(): void
    {
        $issues = $this->check($this->valid(['subtotal' => 1500.00, 'total' => 1612.44]));

        $this->assertContains('line_items_mismatch', $this->codes($issues));
        $this->assertStringContainsString('1,405.47', collect($issues)->firstWhere('code', 'line_items_mismatch')['message']);
    }

    public function test_line_items_are_compared_to_the_total_before_tax_when_subtotal_is_missing(): void
    {
        $issues = $this->check($this->valid(['subtotal' => null]));

        $this->assertNotContains('line_items_mismatch', $this->codes($issues), 'total - tax equals the line item sum');
    }

    public function test_incomplete_line_amounts_skip_the_sum_check(): void
    {
        $items = $this->lines();
        $items[1]['amount'] = null;

        $this->assertNotContains('line_items_mismatch', $this->codes($this->check($this->valid(), $items)));
    }

    public function test_line_calculation_is_flagged_as_a_warning(): void
    {
        $items = $this->lines();
        $items[0]['amount'] = 500.00; // 12 x 38.50 = 462.00

        $issue = collect($this->check($this->valid(['subtotal' => 1443.47, 'tax' => 112.44, 'total' => 1555.91]), $items))->firstWhere('code', 'line_calculation');

        $this->assertNotNull($issue);
        $this->assertSame('warning', $issue['severity']);
        $this->assertSame('line_items.0', $issue['field']);
    }

    public function test_due_date_before_invoice_date_is_an_error(): void
    {
        $issues = $this->check($this->valid(['invoice_date' => '2026-10-20', 'due_date' => '2026-10-05']));

        $this->assertSame('error', collect($issues)->firstWhere('code', 'due_before_invoice')['severity']);
    }

    public function test_future_and_stale_invoice_dates_are_warnings(): void
    {
        $this->assertContains('future_invoice_date', $this->codes($this->check($this->valid(['invoice_date' => '2026-12-01', 'due_date' => '2027-01-01']))));
        $this->assertContains('old_invoice_date', $this->codes($this->check($this->valid(['invoice_date' => '2023-01-10', 'due_date' => '2023-02-10']))));
    }

    public function test_currency_format_and_support(): void
    {
        $this->assertContains('currency_format', $this->codes($this->check($this->valid(['currency' => 'US']))));
        $this->assertContains('unknown_currency', $this->codes($this->check($this->valid(['currency' => 'XYZ']))));
    }

    public function test_negative_amounts_and_high_tax_rates_warn(): void
    {
        $this->assertContains('negative_amount', $this->codes($this->check($this->valid(['total' => -10.0]))));
        $this->assertContains('tax_rate_high', $this->codes($this->check($this->valid(['tax' => 500.0, 'total' => 1905.47]))));
    }

    public function test_missing_line_items_warn(): void
    {
        $this->assertContains('no_line_items', $this->codes($this->check($this->valid(), [])));
    }

    public function test_low_confidence_values_are_surfaced_but_blank_values_are_not(): void
    {
        $issues = $this->check($this->valid(['due_date' => null]), null, ['supplier_name' => 0.5, 'invoice_number' => 0.95, 'due_date' => 0.1]);

        $low = collect($issues)->where('code', 'low_confidence');
        $this->assertCount(1, $low);
        $this->assertSame('supplier_name', $low->first()['field']);
    }
}
