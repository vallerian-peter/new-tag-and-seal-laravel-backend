<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperative_user_memberships', function (Blueprint $table) {
            $table->foreignId('identity_card_type_id')->nullable()->constrained('identity_card_types')->nullOnDelete();
            $table->string('identity_number', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cooperative_user_memberships', function (Blueprint $table) {
            $table->dropForeign(['identity_card_type_id']);
            $table->dropColumn(['identity_card_type_id', 'identity_number']);
        });
    }
};

