<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dairy_factories', function (Blueprint $table) {
            $table->string('tin_number', 20)->nullable();
            $table->string('contact_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('dairy_factories', function (Blueprint $table) {
            $table->dropColumn('tin_number');
            // Keep contact_name nullable: company-only registrations have no personal contact.
        });
    }
};
