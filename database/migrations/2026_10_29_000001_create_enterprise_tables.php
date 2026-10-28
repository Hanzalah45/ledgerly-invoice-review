<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('cost_center', 40)->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('assigned_to')->constrained('departments')->nullOnDelete();
            $table->string('gl_code', 40)->nullable()->after('department_id');
            $table->string('channel', 12)->default('upload')->after('gl_code');          // upload | email
            $table->string('inbound_from')->nullable()->after('channel');
            $table->string('inbound_message_id')->nullable()->unique()->after('inbound_from');
        });

        Schema::create('document_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step_order');
            $table->string('step_type', 24);                 // reviewer | department_manager | admin
            $table->string('status', 12)->default('pending'); // pending | approved
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_approvals');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['gl_code', 'channel', 'inbound_from', 'inbound_message_id']);
        });
        Schema::dropIfExists('departments');
    }
};
