<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();

            // Bill number format: BILL-YYYYMMDDHHMMSS-XXX (up to 30 chars)
            $table->string('billNo', 30)->unique();

            // Scope and relationships
            $table->string('farmUuid');
            $table->index('farmUuid');

            $table->foreignId('extensionOfficerId')
                ->nullable()
                ->constrained('extension_officers')
                ->nullOnDelete();

            $table->foreignId('farmerId')
                ->nullable()
                ->constrained('farmers')
                ->nullOnDelete();

            // Subject linkage (uuid + index)
            $table->string('subjectType');
            $table->string('subjectUuid');
            $table->index('subjectUuid');

            // Commercial details
            $table->integer('quantity')->default(1);
            $table->string('amount'); // amount as string
            $table->enum('status', ['pending', 'paid'])->default('pending'); // enum

            // Notes (replace prior moreDetails)
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
