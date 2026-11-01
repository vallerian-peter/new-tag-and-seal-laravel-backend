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
        $tableName = Schema::hasTable('birth_problems') ? 'birth_problems' : 'calving_problems';

        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'livestockTypeId')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->foreignId('livestockTypeId')->nullable()->after('name')->constrained('livestock_types')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableName = Schema::hasTable('birth_problems') ? 'birth_problems' : 'calving_problems';

        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'livestockTypeId')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropForeign(['livestockTypeId']);
            $table->dropColumn('livestockTypeId');
        });
    }
};
