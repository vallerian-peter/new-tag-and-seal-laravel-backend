<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lookup_sets', function (Blueprint $table): void {
            $table->uuid('parent_lookup_set_uuid')->nullable()->after('translations_json')->index();
        });
        Schema::table('lookup_options', function (Blueprint $table): void {
            $table->uuid('parent_option_uuid')->nullable()->after('translations_json')->index();
        });
    }

    public function down(): void
    {
        Schema::table('lookup_options', fn (Blueprint $table) => $table->dropColumn('parent_option_uuid'));
        Schema::table('lookup_sets', fn (Blueprint $table) => $table->dropColumn('parent_lookup_set_uuid'));
    }
};
