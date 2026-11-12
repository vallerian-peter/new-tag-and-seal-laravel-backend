<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factory_operators', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->foreignId('identity_card_type_id')->nullable()->constrained('identity_card_types')->nullOnDelete();
            $table->string('identity_number', 100)->nullable();
            $table->string('tin_number', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('factory_operators', function (Blueprint $table) {
            $table->dropForeign(['identity_card_type_id']);
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'identity_card_type_id', 'identity_number', 'tin_number']);
        });
    }
};
