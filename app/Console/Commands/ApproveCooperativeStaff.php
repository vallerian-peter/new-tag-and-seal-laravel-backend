<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Portal-style bootstrap: approve a staff user for a cooperative. */
class ApproveCooperativeStaff extends Command
{
    protected $signature = 'cooperative:approve-staff {phone} {amcos_code} {--role=amcos_admin}';
    protected $description = 'Approve a pending cooperative staff membership (all centres of the AMCOS)';

    public function handle(): int
    {
        $suffix = substr(preg_replace('/\D/', '', $this->argument('phone')), -9);
        $user = DB::table('users')->where('phone', 'like', "%{$suffix}%")->first();
        $amcos = DB::table('cooperative_amcos')->where('amcos_code', $this->argument('amcos_code'))->first();
        if (! $user || ! $amcos) {
            $this->error('User or AMCOS not found.');
            return self::FAILURE;
        }
        $membership = DB::table('cooperative_user_memberships')
            ->where('user_id', $user->id)->where('amcos_uuid', $amcos->uuid)->first();
        if (! $membership) {
            $this->error('The user has not requested membership yet.');
            return self::FAILURE;
        }
        DB::table('cooperative_user_memberships')->where('id', $membership->id)->update([
            'status' => 'approved',
            'role' => $this->option('role'),
            'assigned_centre_uuids' => json_encode(
                DB::table('cooperative_collection_centres')->where('amcos_uuid', $amcos->uuid)->pluck('uuid')->all()
            ),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);
        $this->info("Approved {$user->id} for {$amcos->name} as {$this->option('role')}.");
        return self::SUCCESS;
    }
}

