<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transporter_drivers', 'middle_name')) {
            Schema::table('transporter_drivers', fn (Blueprint $table) => $table->string('middle_name', 120)->nullable()->after('first_name'));
        }
    }

    public function down(): void
    {
        Schema::table('transporter_drivers', fn (Blueprint $table) => $table->dropColumn('middle_name'));
    }
};
