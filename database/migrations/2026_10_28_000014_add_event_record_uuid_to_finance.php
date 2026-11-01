<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bills, expenses and incomes point at legacy log rows by the app's legacy uuid.
 * `eventRecordUuid` holds the ledger event behind that row, so finance survives
 * the retirement of the legacy log tables.
 */
return new class extends Migration
{
    private const TABLES = ['bills', 'finance_expenses', 'finance_incomes'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'eventRecordUuid')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->uuid('eventRecordUuid')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'eventRecordUuid')) {
                Schema::table($name, function (Blueprint $table): void {
                    $table->dropIndex(['eventRecordUuid']);
                    $table->dropColumn('eventRecordUuid');
                });
            }
        }
    }
};
