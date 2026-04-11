<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Birth tracking: link offspring to birth_events, production stage, identification state;
     * birth event litter counts; optional identification audit trail.
     */
    public function up(): void
    {
        Schema::table('birth_events', function (Blueprint $table) {
            $table->unsignedInteger('totalBorn')->nullable()->after('remarks');
            $table->unsignedInteger('aliveCount')->nullable()->after('totalBorn');
            $table->unsignedInteger('deadCount')->nullable()->after('aliveCount');
        });

        Schema::table('livestocks', function (Blueprint $table) {
            $table->dropUnique(['identificationNumber']);
        });

        Schema::table('livestocks', function (Blueprint $table) {
            $table->string('identificationNumber')->nullable()->change();
        });

        Schema::table('livestocks', function (Blueprint $table) {
            $table->string('birthEventUuid', 100)->nullable()->after('fatherUuid');
            // FK below adds its own index; a separate index here can duplicate-index MySQL (errno 1061).
            $table->foreignId('stageId')->nullable()->after('birthEventUuid')->constrained('stages')->nullOnDelete();
            $table->boolean('isIdentified')->default(true)->after('stageId');
        });

        Schema::table('livestocks', function (Blueprint $table) {
            $table->foreign('birthEventUuid')
                ->references('uuid')
                ->on('birth_events')
                ->nullOnDelete();
        });

        Schema::create('identification_events', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 100)->unique();
            $table->string('farmUuid');
            $table->index('farmUuid');
            $table->string('livestockUuid', 100);
            $table->index('livestockUuid');
            $table->string('identificationNumber')->nullable();
            $table->dateTime('eventDate');
            $table->string('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identification_events');

        Schema::table('livestocks', function (Blueprint $table) {
            $table->dropForeign(['birthEventUuid']);
        });

        Schema::table('livestocks', function (Blueprint $table) {
            $table->dropForeign(['stageId']);
            $table->dropColumn(['birthEventUuid', 'stageId', 'isIdentified']);
        });

        Schema::table('livestocks', function (Blueprint $table) {
            $table->string('identificationNumber')->nullable(false)->change();
        });

        Schema::table('livestocks', function (Blueprint $table) {
            $table->unique('identificationNumber');
        });

        Schema::table('birth_events', function (Blueprint $table) {
            $table->dropColumn(['totalBorn', 'aliveCount', 'deadCount']);
        });
    }
};
