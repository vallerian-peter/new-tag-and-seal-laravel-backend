<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_identities', function (Blueprint $table) {
            $table->boolean('pin_pending')->default(false)->after('pin_hash');
        });

        // AMCOS enrollment created these logins with a random PIN; rows never updated since were never claimed.
        $unclaimed = DB::table('phone_identities as pi')
            ->join('users as u', 'u.id', '=', 'pi.user_id')
            ->where('u.email', 'like', 'farmer%@tagandseal.local')
            ->whereColumn('pi.created_at', 'pi.updated_at')
            ->pluck('pi.id');
        DB::table('phone_identities')->whereIn('id', $unclaimed)->update(['pin_pending' => true]);
    }

    public function down(): void
    {
        Schema::table('phone_identities', fn (Blueprint $table) => $table->dropColumn('pin_pending'));
    }
};
