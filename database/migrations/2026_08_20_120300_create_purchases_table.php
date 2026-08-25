<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->string('supplier_name')->nullable();
            $table->string('document_number')->nullable();
            $table->timestamp('purchased_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['id', 'company_id']);
            $table->unique(['company_id', 'document_number']);
            $table->index(['company_id', 'branch_id', 'status', 'purchased_at']);
            $table->foreign(['branch_id', 'company_id'])
                ->references(['id', 'company_id'])
                ->on('branches')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
