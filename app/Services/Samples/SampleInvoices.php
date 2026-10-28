<?php

namespace App\Services\Samples;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

/**
 * Generates realistic, fully synthetic invoices as PDFs so the application can be demonstrated
 * without real customer data. Several are intentionally flawed to exercise validation.
 */
class SampleInvoices
{
    /** @return array<int,array<string,mixed>> */
    public static function definitions(): array
    {
        return [
            [
                'file' => 'northwind-logistics-INV-2026-0412.pdf',
                'supplier' => 'Northwind Freight Ltd', 'address' => "14 Dockside Road\nFelixstowe IP11 3QY, United Kingdom",
                'number' => 'INV-2026-0412', 'date' => '2026-09-02', 'due' => '2026-10-02', 'currency' => 'GBP', 'symbol' => '£', 'taxLabel' => 'VAT', 'taxRate' => 20,
                'billTo' => 'Harbourview Retail Group',
                'items' => [['Container drayage, Felixstowe to Daventry', 4, 385.00], ['Customs clearance (per entry)', 4, 65.00], ['Demurrage, 2 days', 2, 120.00]],
            ],
            [
                'file' => 'atlas-office-supplies-AOS-77231.pdf',
                'supplier' => 'Atlas Office Supplies Inc.', 'address' => "2200 Market Street, Suite 410\nPhiladelphia, PA 19103, USA",
                'number' => 'AOS-77231', 'date' => '2026-09-08', 'due' => '2026-10-08', 'currency' => 'USD', 'symbol' => '$', 'taxLabel' => 'Sales tax', 'taxRate' => 8,
                'billTo' => 'Brightline Dental Group',
                'items' => [['A4 copy paper, 5000 sheets', 12, 38.50], ['Toner cartridge HP 26X', 3, 119.99], ['Ergonomic task chair', 2, 249.00], ['Whiteboard markers (box of 12)', 6, 14.25]],
            ],
            [
                'file' => 'meridian-cloud-MC-2026-09-118.pdf',
                'supplier' => 'Meridian Cloud Services LLC', 'address' => "500 Howard Street\nSan Francisco, CA 94105, USA",
                'number' => 'MC-2026-09-118', 'date' => '2026-09-30', 'due' => '2026-10-30', 'currency' => 'USD', 'symbol' => '$', 'taxLabel' => 'Tax', 'taxRate' => 0,
                'billTo' => 'Pioneer Analytics Co.',
                'items' => [['Compute instances, September', 1, 2480.00], ['Managed database cluster', 1, 1290.50], ['Object storage, 18 TB', 18, 23.00], ['Premium support plan', 1, 499.00]],
            ],
            [
                'file' => 'kestrel-consulting-KC-0093.pdf',
                'supplier' => 'Kestrel Consulting GmbH', 'address' => "Friedrichstraße 112\n10117 Berlin, Germany",
                'number' => 'KC-0093', 'date' => '2026-09-14', 'due' => '2026-09-28', 'currency' => 'EUR', 'symbol' => '€', 'taxLabel' => 'VAT', 'taxRate' => 19,
                'billTo' => 'Lindenhof Maschinenbau AG',
                'items' => [['Process audit workshop (day rate)', 5, 1100.00], ['Final report and presentation', 1, 1800.00]],
            ],
            [
                'file' => 'harbor-catering-HC-5520.pdf',
                'supplier' => 'Harbor & Vine Catering Co.', 'address' => "88 Pier Avenue\nSan Diego, CA 92101, USA",
                'number' => 'HC-5520', 'date' => '2026-10-03', 'due' => '2026-10-17', 'currency' => 'USD', 'symbol' => '$', 'taxLabel' => 'Sales tax', 'taxRate' => 7.75,
                'billTo' => 'Brightline Dental Group',
                'items' => [['Working lunch, 40 guests', 1, 1160.00], ['Beverage service', 40, 4.50], ['Delivery and setup', 1, 85.00]],
            ],
            [
                'file' => 'solstice-print-SP-20418.pdf',
                'supplier' => 'Solstice Print & Packaging Ltd', 'address' => "Unit 7, Calder Business Park\nLeeds LS12 6AB, United Kingdom",
                'number' => 'SP-20418', 'date' => '2026-10-06', 'due' => '2026-11-05', 'currency' => 'GBP', 'symbol' => '£', 'taxLabel' => 'VAT', 'taxRate' => 20,
                'billTo' => 'Harbourview Retail Group',
                'items' => [['Retail boxes, matte finish (1,000)', 3, 410.00], ['Tissue paper, branded', 5, 62.40], ['Plates and proofing', 1, 140.00]],
            ],
            [
                'file' => 'orbit-telecom-OT-3381907.pdf',
                'supplier' => 'Orbit Telecom Inc.', 'address' => "1 Commerce Plaza\nToronto, ON M5J 2T3, Canada",
                'number' => 'OT-3381907', 'date' => '2026-10-09', 'due' => '2026-11-08', 'currency' => 'CAD', 'symbol' => '$', 'taxLabel' => 'GST', 'taxRate' => 5,
                'billTo' => 'Pioneer Analytics Co.',
                'items' => [['Business fibre 1 Gbps, October', 1, 289.00], ['Static IP block (/29)', 1, 45.00], ['Voice lines', 8, 18.50]],
            ],
            [
                'file' => 'greenfield-facilities-GF-1187.pdf',
                'supplier' => 'Greenfield Facilities Management LLC', 'address' => "730 Lakeshore Drive\nChicago, IL 60611, USA",
                'number' => 'GF-1187', 'date' => '2026-10-12', 'due' => '2026-11-11', 'currency' => 'USD', 'symbol' => '$', 'taxLabel' => 'Sales tax', 'taxRate' => 10.25,
                'billTo' => 'Brightline Dental Group',
                'items' => [['Janitorial service, October (3 sites)', 3, 640.00], ['Window cleaning, quarterly', 1, 380.00], ['Waste removal', 4, 55.00]],
            ],
            // ------- Deliberately flawed documents (validation demo) -------
            [
                'file' => 'FLAWED-totals-mismatch-bluepeak-BP-9904.pdf',
                'supplier' => 'Bluepeak Software Pty Ltd', 'address' => "Level 12, 100 Collins Street\nMelbourne VIC 3000, Australia",
                'number' => 'BP-9904', 'date' => '2026-10-14', 'due' => '2026-11-13', 'currency' => 'AUD', 'symbol' => '$', 'taxLabel' => 'GST', 'taxRate' => 10,
                'billTo' => 'Pioneer Analytics Co.',
                'items' => [['Annual platform licence', 1, 6400.00], ['Onboarding package', 1, 950.00]],
                'overrideTotal' => 8200.00, // true total is 8,085.00
            ],
            [
                'file' => 'FLAWED-no-invoice-number-fleetline-courier.pdf',
                'supplier' => 'Fleetline Courier Services', 'address' => "9 Wharf Road\nAuckland 1010, New Zealand",
                'number' => null, 'date' => '2026-10-15', 'due' => '2026-11-14', 'currency' => 'NZD', 'symbol' => '$', 'taxLabel' => 'GST', 'taxRate' => 15,
                'billTo' => 'Harbourview Retail Group',
                'items' => [['Same-day courier runs', 22, 14.50], ['Fuel surcharge', 1, 38.00]],
            ],
            [
                'file' => 'FLAWED-due-before-invoice-pinecrest-PC-3002.pdf',
                'supplier' => 'Pinecrest Legal Partners LLP', 'address' => "40 Bay Street, 18th Floor\nToronto, ON M5J 2X2, Canada",
                'number' => 'PC-3002', 'date' => '2026-10-20', 'due' => '2026-10-05', 'currency' => 'CAD', 'symbol' => '$', 'taxLabel' => 'HST', 'taxRate' => 13,
                'billTo' => 'Lindenhof Maschinenbau AG',
                'items' => [['Contract review, partner time (hrs)', 6, 450.00], ['Associate drafting (hrs)', 9, 260.00]],
            ],
            [
                'file' => 'FLAWED-line-items-dont-sum-ridgeway-RT-6610.pdf',
                'supplier' => 'Ridgeway Tools & Hardware Inc.', 'address' => "412 Industrial Parkway\nColumbus, OH 43215, USA",
                'number' => 'RT-6610', 'date' => '2026-10-21', 'due' => '2026-11-20', 'currency' => 'USD', 'symbol' => '$', 'taxLabel' => 'Sales tax', 'taxRate' => 7.5,
                'billTo' => 'Brightline Dental Group',
                'items' => [['Cordless drill kit', 4, 189.00], ['Safety glasses (box of 50)', 2, 36.00], ['Torque wrench set', 1, 214.00]],
                'overrideSubtotal' => 1200.00, // true subtotal is 1,042.00
            ],
            [
                // Same supplier and number as the first sample: exercises duplicate detection.
                'file' => 'DUPLICATE-northwind-logistics-INV-2026-0412.pdf',
                'supplier' => 'Northwind Freight Ltd', 'address' => "14 Dockside Road\nFelixstowe IP11 3QY, United Kingdom",
                'number' => 'INV-2026-0412', 'date' => '2026-09-02', 'due' => '2026-10-02', 'currency' => 'GBP', 'symbol' => '£', 'taxLabel' => 'VAT', 'taxRate' => 20,
                'billTo' => 'Harbourview Retail Group',
                'items' => [['Container drayage, Felixstowe to Daventry', 4, 385.00], ['Customs clearance (per entry)', 4, 65.00], ['Demurrage, 2 days', 2, 120.00]],
                'copyOf' => 0,
            ],
            // A page with no text layer: exercises the failed / error state (no OCR is bundled).
            ['file' => 'SCANNED-image-only-receipt.pdf', 'scanned' => true],
        ];
    }

    /**
     * Order and billing details per sample file: PO number, tax ID, bill-to address, payment terms, bank account,
     * notes, shipping, discount and a monogram standing in for the supplier's logo.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function extras(): array
    {
        return [
            'northwind-logistics-INV-2026-0412.pdf' => ['po' => 'PO-88412', 'taxId' => 'GB 214 7788 31', 'billAddress' => "Harbourview House, 3 Quay Road\nLiverpool L3 1BP, United Kingdom", 'terms' => 'Net 30 days', 'bank' => 'GB29 NWBK 6016 1331 9268 19', 'notes' => 'Container MSKU 4417832. Deliver to Daventry DC.', 'mark' => ['NF', '#1f4e79'], 'sku' => true],
            'atlas-office-supplies-AOS-77231.pdf' => ['po' => 'PO-77001', 'taxId' => 'EIN 23-4410287', 'billAddress' => "880 Walnut Street, Floor 4\nPhiladelphia, PA 19107, USA", 'terms' => 'Net 30 days', 'bank' => 'US 0211 0000 2290 5518', 'notes' => 'Please quote the PO number on all remittances.', 'mark' => ['AO', '#a33b2f'], 'sku' => true, 'shipping' => 24.00],
            'meridian-cloud-MC-2026-09-118.pdf' => ['taxId' => 'EIN 94-3318720', 'billAddress' => "45 Innovation Drive\nAustin, TX 78701, USA", 'terms' => 'Net 30 days', 'bank' => 'US 0260 0959 3122 7700', 'mark' => ['MC', '#3b3b8a']],
            'kestrel-consulting-KC-0093.pdf' => ['po' => 'PO-KC-7731', 'taxId' => 'DE 311 902 447', 'billAddress' => "Industriestraße 40\n70565 Stuttgart, Germany", 'terms' => 'Net 14 days', 'bank' => 'DE89 3704 0044 0532 0130 00', 'notes' => 'Reverse charge does not apply.', 'mark' => ['KC', '#2c6e49']],
            'harbor-catering-HC-5520.pdf' => ['po' => 'PO-BD-3310', 'taxId' => 'EIN 33-9087412', 'billAddress' => "1200 Pacific Highway\nSan Diego, CA 92101, USA", 'terms' => 'Net 14 days', 'bank' => 'US 0900 1040 6670 1123', 'mark' => ['HV', '#7a4e2d'], 'discount' => 58.00],
            'solstice-print-SP-20418.pdf' => ['po' => 'PO-HV-2204', 'taxId' => 'GB 774 3321 09', 'billAddress' => "Harbourview House, 3 Quay Road\nLiverpool L3 1BP, United Kingdom", 'terms' => 'Net 30 days', 'bank' => 'GB82 WEST 1234 5698 7654 32', 'notes' => 'Artwork proofs approved 29 Sep 2026.', 'mark' => ['SP', '#b5651d'], 'sku' => true, 'shipping' => 45.00],
            'orbit-telecom-OT-3381907.pdf' => ['taxId' => 'GST 81234 5678 RT0001', 'billAddress' => "200 Bay Street, Suite 1400\nToronto, ON M5J 2J2, Canada", 'terms' => 'Net 30 days', 'mark' => ['OT', '#0b6e99']],
            'greenfield-facilities-GF-1187.pdf' => ['po' => 'PO-GF-5521', 'taxId' => 'EIN 36-4412098', 'billAddress' => "1500 Dental Plaza\nChicago, IL 60611, USA", 'terms' => 'Net 30 days', 'bank' => 'US 0710 0001 8890 2210', 'mark' => ['GF', '#3d7a3a']],
            'FLAWED-totals-mismatch-bluepeak-BP-9904.pdf' => ['po' => 'PO-PA-1188', 'taxId' => 'ABN 51 824 753 556', 'billAddress' => "88 Analytics Way\nSydney NSW 2000, Australia", 'terms' => 'Net 30 days', 'mark' => ['BP', '#1d3557']],
            'FLAWED-no-invoice-number-fleetline-courier.pdf' => ['taxId' => 'NZBN 9429 0412 3345', 'billAddress' => "Harbourview Retail, 12 Wharf Road\nAuckland 1010, New Zealand", 'terms' => 'Due on receipt', 'mark' => ['FC', '#c1440e']],
            'FLAWED-due-before-invoice-pinecrest-PC-3002.pdf' => ['taxId' => 'HST 874 120 553 RT0001', 'billAddress' => "Maschinenbau Lindenhof AG\nKönigsallee 14, 40212 Düsseldorf, Germany", 'terms' => 'Net 15 days', 'mark' => ['PL', '#4a2c5a']],
            'FLAWED-line-items-dont-sum-ridgeway-RT-6610.pdf' => ['po' => 'PO-BD-3322', 'taxId' => 'EIN 31-7783321', 'billAddress' => "1200 Pacific Highway\nSan Diego, CA 92101, USA", 'terms' => 'Net 30 days', 'mark' => ['RT', '#4d4d4d'], 'sku' => true],
            'DUPLICATE-northwind-logistics-INV-2026-0412.pdf' => ['po' => 'PO-88412', 'taxId' => 'GB 214 7788 31', 'billAddress' => "Harbourview House, 3 Quay Road\nLiverpool L3 1BP, United Kingdom", 'terms' => 'Net 30 days', 'bank' => 'GB29 NWBK 6016 1331 9268 19', 'notes' => 'Container MSKU 4417832. Deliver to Daventry DC.', 'mark' => ['NF', '#1f4e79'], 'sku' => true],
        ];
    }

    /** @return array<string,mixed> invoice data with computed totals */
    public static function compute(array $definition): array
    {
        $definition += self::extras()[$definition['file'] ?? ''] ?? [];

        $lines = [];
        $subtotal = 0.0;
        foreach ($definition['items'] as $i => [$description, $quantity, $unit]) {
            $amount = round($quantity * $unit, 2);
            $subtotal += $amount;
            $lines[] = [
                'sku' => ! empty($definition['sku']) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $definition['supplier']), 0, 3)).'-'.(1000 + $i * 17 + strlen($description)) : null,
                'description' => $description, 'quantity' => $quantity, 'unit' => $unit, 'amount' => $amount,
            ];
        }
        $subtotal = round($subtotal, 2);
        $printedSubtotal = $definition['overrideSubtotal'] ?? $subtotal;
        $discount = (float) ($definition['discount'] ?? 0);
        $shipping = (float) ($definition['shipping'] ?? 0);
        $tax = round(($printedSubtotal - $discount + $shipping) * $definition['taxRate'] / 100, 2);
        $total = $definition['overrideTotal'] ?? round($printedSubtotal - $discount + $shipping + $tax, 2);

        return $definition + [
            'lines' => $lines, 'subtotalPrinted' => $printedSubtotal, 'taxPrinted' => $tax, 'totalPrinted' => $total,
            'discountPrinted' => $discount, 'shippingPrinted' => $shipping,
        ];
    }

    public static function render(array $definition): string
    {
        if (! empty($definition['scanned'])) {
            return Pdf::loadView('pdf.sample-scanned')->setPaper('a4')->output();
        }

        return Pdf::loadView('pdf.sample-invoice', ['invoice' => self::compute($definition)])
            ->setPaper('a4')
            ->output();
    }

    /** Writes every sample PDF into $directory and returns the file paths. */
    public static function writeAll(string $directory): array
    {
        File::ensureDirectoryExists($directory);
        $paths = [];
        foreach (self::definitions() as $definition) {
            $path = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$definition['file'];
            file_put_contents($path, self::render($definition));
            $paths[] = $path;
        }

        return $paths;
    }
}
