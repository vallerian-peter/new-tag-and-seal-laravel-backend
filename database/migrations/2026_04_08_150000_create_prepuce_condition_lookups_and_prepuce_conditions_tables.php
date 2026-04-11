<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepuce_condition_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('category', 64);
            $table->string('code', 64);
            $table->string('label_en');
            $table->string('label_sw')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['category', 'code']);
        });

        Schema::create('prepuce_conditions', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->dateTime('eventDate')->nullable();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid');
            $table->index('livestockUuid');
            $table->string('conditionType', 64);
            $table->string('severity', 32);
            $table->json('clinicalSigns')->nullable();
            $table->string('causeRisk', 64)->nullable();
            $table->json('treatmentGiven')->nullable();
            $table->unsignedBigInteger('medicineId')->nullable()->index();
            $table->unsignedBigInteger('administrationRouteId')->nullable()->index();
            $table->string('vetId', 191)->nullable()->index();
            $table->string('extensionOfficerId', 191)->nullable()->index();
            $table->string('quantity')->nullable();
            $table->string('dose')->nullable();
            $table->string('breedingStatus', 64);
            $table->string('healingStatus', 64)->nullable();
            $table->dateTime('followUpDate')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('medicineId')->references('id')->on('medicines')->nullOnDelete();
            $table->foreign('administrationRouteId')->references('id')->on('administration_routes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prepuce_conditions');
        Schema::dropIfExists('prepuce_condition_lookups');
    }
};
