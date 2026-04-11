<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('sourceType')->default('bill');
            $table->string('sourceUuid');
            $table->string('farmUuid')->nullable()->index();
            $table->foreignId('farmerId')->nullable()->constrained('farmers')->nullOnDelete();
            $table->string('billNo')->nullable();
            $table->string('subjectType')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unitCost', 14, 2)->default(0);
            $table->decimal('totalCost', 14, 2)->default(0);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('expenseDate')->nullable();
            $table->timestamps();

            $table->unique(['sourceType', 'sourceUuid'], 'finance_expenses_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_expenses');
    }
};
