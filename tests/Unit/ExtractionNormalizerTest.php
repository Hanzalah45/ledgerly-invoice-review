<?php

namespace Tests\Unit;

use App\Services\Extraction\ExtractionNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExtractionNormalizerTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_it_parses_amounts_in_common_formats(mixed $input, ?float $expected): void
    {
        $this->assertSame($expected, ExtractionNormalizer::amount($input));
    }

    public static function amounts(): array
    {
        return [
            'us thousands' => ['$1,234.56', 1234.56],
            'european' => ['1.234,56', 1234.56],
            'pounds' => ['£2,448.00', 2448.0],
            'euro prefix' => ['€ 8,687.00', 8687.0],
            'decimal comma' => ['12,50', 12.5],
            'thousands comma only' => ['1,250', 1250.0],
            'millions' => ['1,250,000', 1250000.0],
            'bracket negative' => ['(120.00)', -120.0],
            'minus negative' => ['-45.10', -45.1],
            'plain number' => [99, 99.0],
            'float' => [12.345, 12.345],
            'no digits' => ['n/a', null],
            'empty' => ['', null],
            'null' => [null, null],
            'array' => [[1], null],
        ];
    }

    #[DataProvider('dates')]
    public function test_it_parses_dates(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, ExtractionNormalizer::date($input));
    }

    public static function dates(): array
    {
        return [
            'iso' => ['2026-09-02', '2026-09-02'],
            'day month year' => ['02 Sep 2026', '2026-09-02'],
            'month day year' => ['September 2, 2026', '2026-09-02'],
            'ordinal' => ['2nd Sep 2026', '2026-09-02'],
            'slash us' => ['09/02/2026', '2026-09-02'],
            'slash day first when obvious' => ['25/09/2026', '2026-09-25'],
            'dots are european' => ['02.09.2026', '2026-09-02'],
            'two digit year' => ['09/02/26', '2026-09-02'],
            'garbage' => ['not a date', null],
            'impossible' => ['31/02/2026', null],
            'ancient' => ['1970-01-01', null],
            'far future' => ['2250-01-01', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    public function test_it_normalises_currency_symbols_and_codes(): void
    {
        $this->assertSame('GBP', ExtractionNormalizer::currency('£'));
        $this->assertSame('EUR', ExtractionNormalizer::currency('€'));
        $this->assertSame('USD', ExtractionNormalizer::currency(' usd '));
        $this->assertNull(ExtractionNormalizer::currency('dollars'));
        $this->assertNull(ExtractionNormalizer::currency(null));
    }

    public function test_it_normalises_a_provider_payload_and_clamps_confidence(): void
    {
        $result = (new ExtractionNormalizer)->normalise([
            'fields' => [
                'supplier_name' => "  Atlas   Office\nSupplies ",
                'invoice_number' => 'AOS-77231',
                'invoice_date' => '08 Sep 2026',
                'due_date' => null,
                'currency' => 'usd',
                'subtotal' => '$1,405.47',
                'tax' => '112.44',
                'total' => '1,517.91',
            ],
            'confidence' => ['supplier_name' => 1.7, 'invoice_number' => -2, 'invoice_date' => '0.9', 'due_date' => 0.5],
            'line_items' => [
                ['description' => 'A4 paper', 'quantity' => '12', 'unit_price' => '$38.50', 'amount' => '$462.00', 'confidence' => 0.8],
                ['description' => '', 'amount' => '10'],        // dropped: no description
                'not-an-array',                                  // dropped
            ],
        ]);

        $this->assertSame('Atlas Office Supplies', $result['fields']['supplier_name']);
        $this->assertSame('2026-09-08', $result['fields']['invoice_date']);
        $this->assertSame('USD', $result['fields']['currency']);
        $this->assertSame(1517.91, $result['fields']['total']);
        $this->assertSame(1.0, $result['confidence']['supplier_name']);
        $this->assertSame(0.0, $result['confidence']['invoice_number']);
        $this->assertSame(0.9, $result['confidence']['invoice_date']);
        $this->assertArrayNotHasKey('due_date', $result['confidence'], 'No confidence for a null value');
        $this->assertCount(1, $result['lineItems']);
        $this->assertSame(462.0, $result['lineItems'][0]['amount']);
    }
}
