<?php

namespace App\Services\Dairy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CooperativeMembershipNotifications
{
    public function record(string $membershipUuid, int $userId, string $event): void
    {
        DB::table('cooperative_membership_notifications')->insert([
            'membership_uuid' => $membershipUuid,
            'user_id' => $userId,
            'event' => $event,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function dispatchPending(): int
    {
        if (! config('services.beem_sms.api_key') || ! config('services.beem_sms.secret_key') || ! config('services.beem_sms.sender')) {
            Log::warning('Cooperative membership SMS is not configured; notifications remain pending.');
            return 0;
        }
        DB::table('cooperative_membership_notifications')
            ->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10))
            ->update(['status' => 'failed', 'next_attempt_at' => now(), 'updated_at' => now()]);
        $ids = DB::table('cooperative_membership_notifications')
            ->whereIn('status', ['pending', 'failed'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit(50)->pluck('id');
        $sent = 0;
        foreach ($ids as $id) {
            $claimed = DB::table('cooperative_membership_notifications')->where('id', $id)
                ->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            if (! $claimed) {
                continue;
            }
            $row = DB::table('cooperative_membership_notifications')->where('id', $id)->first();
            try {
                $this->send($row);
                DB::table('cooperative_membership_notifications')->where('id', $id)
                    ->update(['status' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null, 'updated_at' => now()]);
                $sent++;
            } catch (Throwable $e) {
                DB::table('cooperative_membership_notifications')->where('id', $id)
                    ->update(['status' => 'failed', 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);
                Log::warning('Cooperative membership SMS failed.', ['notification_id' => $id, 'error_type' => get_class($e)]);
            }
        }

        return $sent;
    }

    private function send(object $notification): void
    {
        $phone = DB::table('phone_identities')->where('user_id', $notification->user_id)->value('phone');
        $key = config('services.beem_sms.api_key');
        $secret = config('services.beem_sms.secret_key');
        $sender = config('services.beem_sms.sender');
        if (! $phone || ! $key || ! $secret || ! $sender) {
            throw new RuntimeException('SMS configuration or recipient unavailable.');
        }
        $amcosName = DB::table('cooperative_user_memberships as cum')
            ->join('cooperative_amcos as a', 'a.uuid', '=', 'cum.amcos_uuid')
            ->where('cum.uuid', $notification->membership_uuid)
            ->value('a.name');
        $amcos = $amcosName ? "AMCOS {$amcosName}" : 'AMCOS';
        $message = match ($notification->event) {
            'submitted' => "Tag & Seal: Ombi lako la kusajiliwa kama mtumiaji wa {$amcos} limepokelewa na linasubiri idhini.",
            'approved' => "Tag & Seal: Hongera! Umesajiliwa kama mtumiaji wa {$amcos}. Sasa unaweza kutumia huduma ya Maziwa kwenye programu ya Tag & Seal.",
            'rejected' => "Tag & Seal: Ombi lako la kusajiliwa kama mtumiaji wa {$amcos} halijaidhinishwa. Unaweza kuomba tena.",
            default => throw new RuntimeException('Unknown membership notification event.'),
        };
        $response = Http::timeout(15)->withBasicAuth($key, $secret)->post('https://apisms.beem.africa/v1/send', [
            'source_addr' => $sender,
            'encoding' => 0,
            'schedule_time' => '',
            'message' => $message,
            'recipients' => [['recipient_id' => '1', 'dest_addr' => ltrim($phone, '+')]],
        ]);
        if (! $response->successful() || $response->json('successful') !== true) {
            throw new RuntimeException('SMS provider did not accept the message.');
        }
    }
}
