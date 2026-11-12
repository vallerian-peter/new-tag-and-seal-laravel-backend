<?php

namespace App\Console\Commands;

use App\Services\PhoneAuthentication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PhoneIdentityAudit extends Command
{
    protected $signature = 'phone-auth:audit';

    protected $description = 'Audit duplicate and conflicting phone numbers before phone sign-in rollout';

    public function handle(PhoneAuthentication $phones): int
    {
        $numbers = [];
        $legacyByUser = [];
        $invalidUsers = [];
        $conflicts = 0;
        foreach ($phones->legacyPhoneRows() as $row) {
            $phone = $phones->normalize((string) $row->phone);
            if ($phone !== null) {
                $numbers[$phone][(string) $row->id] = true;
                $legacyByUser[(string) $row->id][$phone] = true;
            } else {
                $invalidUsers[(string) $row->id] = true;
            }
        }
        foreach (DB::table('phone_identities')->select('user_id', 'phone')->get() as $identity) {
            $numbers[$identity->phone][(string) $identity->user_id] = true;
        }
        foreach (array_keys($invalidUsers) as $id) {
            $conflicts++;
            $this->line('User ID '.$id.' has an invalid legacy phone number.');
        }
        foreach ($numbers as $phone => $users) {
            $ids = array_keys($users);
            if (count($ids) > 1) {
                $conflicts++;
                $this->line('••••'.substr($phone, -4).' — user IDs: '.implode(', ', $ids));
            }
        }
        foreach (DB::table('phone_identities')->select('user_id', 'phone')->get() as $identity) {
            foreach (array_keys($legacyByUser[(string) $identity->user_id] ?? []) as $phone) {
                if ($phone !== $identity->phone) {
                    $conflicts++;
                    $this->line('User ID '.$identity->user_id.' has a legacy phone conflicting with its verified identity.');
                }
            }
        }
        $this->info(count($numbers).' normalized phone numbers checked; '.$conflicts.' conflict(s).');

        return $conflicts ? self::FAILURE : self::SUCCESS;
    }
}
