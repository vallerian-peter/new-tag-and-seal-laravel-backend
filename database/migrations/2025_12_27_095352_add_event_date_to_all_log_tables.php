<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds eventDate column to all log tables.
     * eventDate represents when the event actually occurred (user-editable),
     * separate from created_at which tracks when the record was created in the system.
     */
    public function up(): void
    {
        $logTables = [
            'treatments',
            'feedings',
            'vaccinations',
            'dewormings',
            'weight_changes',
            'disposals',
            'birth_events',
            'aborted_pregnancies',
            'milkings',
            'pregnancies',
            'inseminations',
            'dryoffs',
            'transfers',
            'calvings',
        ];

        foreach ($logTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dateTime('eventDate')
                        ->nullable()
                        ->comment('Date and time when the event actually occurred (user-editable)')
                        ->after('uuid');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $logTables = [
            'treatments',
            'feedings',
            'vaccinations',
            'dewormings',
            'weight_changes',
            'disposals',
            'birth_events',
            'aborted_pregnancies',
            'milkings',
            'pregnancies',
            'inseminations',
            'dryoffs',
            'transfers',
            'calvings',
        ];

        foreach ($logTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('eventDate');
                });
            }
        }
    }
};
