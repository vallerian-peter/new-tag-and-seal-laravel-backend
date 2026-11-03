<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends an SMS without blocking the API response. Dispatched with
 * `dispatchAfterResponse()` so it works even when no queue worker is running.
 */
class SendVetServiceSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param array<int, string> $phoneNumbers
     */
    public function __construct(public array $phoneNumbers, public string $message)
    {
    }

    public function handle(SmsService $sms): void
    {
        $numbers = array_values(array_unique(array_filter($this->phoneNumbers)));
        if (empty($numbers)) {
            return;
        }
        $sms->sendSms($this->message, $numbers);
    }
}
