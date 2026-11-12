<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SendHiringSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public function __construct(public int $notificationId) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(SmsService $sms): void
    {
        $row = DB::transaction(function () {
            $row = DB::table('hiring_notifications')->where('id', $this->notificationId)->lockForUpdate()->first();
            if (! $row || in_array($row->sms_status, ['sent', 'unavailable', 'sending'], true) || $row->sms_attempts >= 5) {
                return null;
            }
            DB::table('hiring_notifications')->where('id', $row->id)->update(['sms_status' => 'sending', 'sms_attempts' => $row->sms_attempts + 1, 'updated_at' => now()]);

            return $row;
        });
        if (! $row) {
            return;
        }
        try {
            $text = ($row->message_en && $row->message_en !== $row->message_sw)
                ? $row->message_sw."\n".$row->message_en
                : $row->message_sw;
            $result = $sms->sendSms($text, $row->phone);
            if (is_string($result) || (isset($result['status']) && in_array(strtolower((string) $result['status']), ['error', 'failed'], true))) {
                throw new \RuntimeException('SMS_DELIVERY_FAILED');
            }
            DB::table('hiring_notifications')->where('id', $row->id)->update(['sms_status' => 'sent', 'sms_sent_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $error) {
            DB::table('hiring_notifications')->where('id', $row->id)->update(['sms_status' => 'failed', 'sms_next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);
            throw $error;
        }
    }
}
