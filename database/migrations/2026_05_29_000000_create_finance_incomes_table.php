<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_incomes', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('sourceType')->nullable();
            $table->string('sourceUuid')->nullable();
            $table->string('farmUuid')->nullable()->index();
            $table->foreignId('farmerId')->nullable()->constrained('farmers')->nullOnDelete();
            $table->string('referenceNo')->nullable();
            $table->string('subjectType')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('unitAmount', 14, 2)->default(0);
            $table->decimal('totalAmount', 14, 2)->default(0);
            $table->enum('status', ['pending', 'received'])->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('incomeDate')->nullable();
            $table->timestamps();

            $table->unique(['sourceType', 'sourceUuid'], 'finance_incomes_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_incomes');
    }
};
