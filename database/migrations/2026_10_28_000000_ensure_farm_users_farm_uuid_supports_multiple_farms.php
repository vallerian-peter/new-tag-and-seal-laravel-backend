<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('farm_users') || ! Schema::hasColumn('farm_users', 'farmUuid')) {
            return;
        }

        if (Schema::getColumnType('farm_users', 'farmUuid') === 'text') {
            return;
        }

        $farmUuidIndexExists = collect(Schema::getIndexes('farm_users'))
            ->contains(fn (array $index): bool => $index['name'] === 'farm_users_farmuuid_index');

        if ($farmUuidIndexExists) {
            Schema::table('farm_users', function (Blueprint $table): void {
                $table->dropIndex('farm_users_farmuuid_index');
            });
        }

        Schema::table('farm_users', function (Blueprint $table): void {
            $table->text('farmUuid')->change();
        });
    }

    public function down(): void
    {
        // This compatibility migration is intentionally non-destructive.
        // Multiple farm UUIDs may not fit back into a VARCHAR column.
    }
};
