<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Database-only documents (no file on disk) for testing lists, filters, exports and workflow rules.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 100, 5000);
        $tax = round($subtotal * 0.2, 2);

        return [
            'original_name' => fake()->slug(3).'.pdf',
            'stored_path' => 'documents/'.fake()->uuid().'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(20_000, 900_000),
            'sha256' => hash('sha256', fake()->uuid()),
            'status' => DocumentStatus::Ready,
            'provider' => 'demo',
            'model' => 'rules-v1',
            'is_demo' => true,
            'supplier_name' => fake()->company(),
            'invoice_number' => strtoupper(fake()->bothify('INV-####-??')),
            'invoice_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'currency' => 'USD',
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
            'confidence' => ['supplier_name' => 0.9, 'invoice_number' => 0.93, 'invoice_date' => 0.92, 'total' => 0.93],
            'validation_issues' => [],
            'error_count' => 0,
            'warning_count' => 0,
            'processed_at' => now(),
        ];
    }

    public function status(DocumentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function approved(): static
    {
        return $this->state(['status' => DocumentStatus::Approved, 'reviewed_at' => now()]);
    }

    /** A document that fails validation with one blocking error. */
    public function withError(string $message = 'Total does not equal subtotal + tax.'): static
    {
        return $this->state([
            'status' => DocumentStatus::NeedsReview,
            'validation_issues' => [['field' => 'total', 'severity' => 'error', 'code' => 'total_mismatch', 'message' => $message]],
            'error_count' => 1,
        ]);
    }
}
