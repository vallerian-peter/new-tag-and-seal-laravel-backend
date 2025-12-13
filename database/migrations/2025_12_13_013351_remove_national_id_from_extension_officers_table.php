<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('extension_officers', function (Blueprint $table) {
            $table->dropColumn('nationalId');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('extension_officers', function (Blueprint $table) {
            $table->string('nationalId')->nullable()->after('gender');
        });
    }
};
