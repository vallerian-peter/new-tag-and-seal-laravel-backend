<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With explicit_defaults_for_timestamp off, MySQL/MariaDB gives the first TIMESTAMP
 * column of a table ON UPDATE CURRENT_TIMESTAMP, so voiding an event rewrote when it
 * happened. These columns record business time and must only change when set.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'configuration_revisions' => 'published_at',
        'dynamic_mutation_receipts' => 'created_at',
        'event_records' => 'occurred_at',
        'otp_verifications' => 'expires_at',
        'platform_change_journal' => 'captured_at',
        'subject_identifier_events' => 'occurred_at',
        'subject_identifiers' => 'assigned_at',
        'sync_tombstones' => 'occurred_at',
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::COLUMNS as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                DB::statement("ALTER TABLE `$table` MODIFY `$column` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
            }
        }
    }

    public function down(): void
    {
        // The automatic update was never intended; nothing to restore.
    }
};
