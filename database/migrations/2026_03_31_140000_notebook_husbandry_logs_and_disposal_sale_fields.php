<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teeth_clippings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->dateTime('eventDate')->nullable();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid');
            $table->index('livestockUuid');
            $table->string('method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tail_dockings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->dateTime('eventDate')->nullable();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid');
            $table->index('livestockUuid');
            $table->string('method')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('iron_injections', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->dateTime('eventDate')->nullable();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid');
            $table->index('livestockUuid');
            $table->string('dosage')->nullable();
            $table->foreignId('medicineId')->nullable()->constrained('medicines')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('livestock_markings', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->dateTime('eventDate')->nullable();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid');
            $table->index('livestockUuid');
            $table->string('markingType', 50);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('stage_changes', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->dateTime('eventDate')->nullable();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid');
            $table->index('livestockUuid');
            $table->foreignId('fromStageId')->nullable()->constrained('stages')->nullOnDelete();
            $table->foreignId('toStageId')->nullable()->constrained('stages')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('disposals', function (Blueprint $table) {
            $table->decimal('saleWeight', 10, 2)->nullable();
            $table->decimal('salePrice', 12, 2)->nullable();
            $table->string('buyerName')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('disposals', function (Blueprint $table) {
            $table->dropColumn(['saleWeight', 'salePrice', 'buyerName']);
        });

        Schema::dropIfExists('stage_changes');
        Schema::dropIfExists('livestock_markings');
        Schema::dropIfExists('iron_injections');
        Schema::dropIfExists('tail_dockings');
        Schema::dropIfExists('teeth_clippings');
    }
};
