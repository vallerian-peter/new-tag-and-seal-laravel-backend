<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legacy_record_mappings', function (Blueprint $table): void {
            // Several historical tables used composite/time-based identifiers that
            // are intentionally preserved verbatim during the lossless upgrade.
            $table->string('source_uuid', 191)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('legacy_record_mappings', function (Blueprint $table): void {
            $table->string('source_uuid', 36)->nullable()->change();
        });
    }
};
