<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lookup_sets', function (Blueprint $table): void {
            $table->string('legacy_table', 100)->nullable()->after('is_extensible');
            $table->index('legacy_table');
        });
        Schema::table('lookup_options', function (Blueprint $table): void {
            $table->unsignedBigInteger('legacy_id')->nullable()->after('code');
        });
        // An event can belong to a different family than its behaviour (a record-only
        // vaccination is health); null falls back to the behaviour's family.
        Schema::table('event_definitions', function (Blueprint $table): void {
            $table->string('family', 50)->nullable()->after('behavior_code');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('event_definitions', 'family')) {
            Schema::table('event_definitions', fn (Blueprint $table) => $table->dropColumn('family'));
        }
        Schema::table('lookup_options', fn (Blueprint $table) => $table->dropColumn('legacy_id'));
        Schema::table('lookup_sets', function (Blueprint $table): void {
            $table->dropIndex(['legacy_table']);
            $table->dropColumn('legacy_table');
        });
    }
};
