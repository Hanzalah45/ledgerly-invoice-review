<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // File
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime', 100)->default('application/pdf');
            $table->unsignedBigInteger('size')->default(0);
            $table->char('sha256', 64)->index();
            $table->unsignedSmallInteger('page_count')->nullable();

            // Processing
            $table->string('status', 20)->default('uploaded')->index();
            $table->string('provider', 30)->nullable();      // demo | openai | anthropic
            $table->string('model', 80)->nullable();
            $table->boolean('is_demo')->default(false);      // true when output came from the rule-based demo parser
            $table->longText('extracted_text')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();

            // Extracted, human-editable fields
            $table->string('supplier_name')->nullable()->index();
            $table->string('invoice_number', 80)->nullable()->index();
            $table->date('invoice_date')->nullable()->index();
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('subtotal', 14, 2)->nullable();
            $table->decimal('tax', 14, 2)->nullable();
            $table->decimal('total', 14, 2)->nullable();

            // Order & billing details
            $table->string('po_number', 80)->nullable()->index();
            $table->text('supplier_address')->nullable();
            $table->string('supplier_tax_id', 40)->nullable();
            $table->string('bill_to_name')->nullable();
            $table->text('bill_to_address')->nullable();
            $table->string('payment_terms', 120)->nullable();
            $table->decimal('discount', 14, 2)->nullable();
            $table->decimal('shipping', 14, 2)->nullable();
            $table->string('bank_account', 60)->nullable();
            $table->text('invoice_notes')->nullable();

            $table->json('confidence')->nullable();          // field => 0..1
            $table->json('validation_issues')->nullable();   // [{field,severity,code,message}]
            $table->unsignedSmallInteger('error_count')->default(0)->index();   // denormalised from validation_issues
            $table->unsignedSmallInteger('warning_count')->default(0);
            $table->boolean('human_edited')->default(false);

            // Review
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();
        });

        Schema::create('document_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('sku', 60)->nullable();
            $table->string('description');
            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('unit_price', 14, 2)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40)->index();
            $table->string('message');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('document_line_items');
        Schema::dropIfExists('documents');
    }
};
