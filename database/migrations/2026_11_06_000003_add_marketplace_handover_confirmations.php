<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dateTime('seller_handover_confirmed_at')->nullable();
            $table->dateTime('buyer_handover_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['seller_handover_confirmed_at', 'buyer_handover_confirmed_at']);
        });
    }
};
