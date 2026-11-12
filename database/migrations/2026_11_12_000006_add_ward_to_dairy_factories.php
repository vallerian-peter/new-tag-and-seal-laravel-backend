<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('dairy_factories', function (Blueprint $table) { $table->foreignId('ward_id')->nullable()->constrained('wards')->nullOnDelete(); }); }
    public function down(): void { Schema::table('dairy_factories', function (Blueprint $table) { $table->dropForeign(['ward_id']); $table->dropColumn('ward_id'); }); }
};
