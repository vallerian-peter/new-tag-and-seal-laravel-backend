<?php

namespace App\Services\Hiring;

use Illuminate\Support\Facades\DB;

class HiringNotifications
{
    public function send(string $event, ?int $user, ?string $phone, string $kind, string $en, string $sw, array $payload = []): void
    {
        if ($user) {
            $phone = DB::table('phone_identities')->where('user_id', $user)->value('phone') ?: $phone;
        }
        $inserted = DB::table('hiring_notifications')->insertOrIgnore(['event_key' => $event, 'user_id' => $user, 'phone' => $phone, 'kind' => $kind, 'payload' => json_encode($payload), 'message_en' => $en, 'message_sw' => $sw, 'sms_status' => $phone ? 'pending' : 'unavailable', 'created_at' => now(), 'updated_at' => now()]);
        // The persistent outbox is dispatched by the scheduler after commit.
    }
}
