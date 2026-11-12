<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dairy_trips', function (Blueprint $table) {
            if (! Schema::hasColumn('dairy_trips', 'welcoming_operator_uuid')) {
                $table->string('welcoming_operator_uuid', 64)->nullable();
            }
            if (! Schema::hasColumn('dairy_trips', 'arrival_notes')) {
                $table->text('arrival_notes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dairy_trips', function (Blueprint $table) {
            $table->dropColumn(['welcoming_operator_uuid', 'arrival_notes']);
        });
    }
};
