<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PhoneAuthentication
{
    /** Raw response from the SMS gateway for the last sendCode() call (diagnostics). */
    public mixed $lastGatewayResponse = null;

    public function normalize(string $phone): ?string
    {
        $phone = trim($phone);
        if (! preg_match('/^\+?[0-9\s().-]+$/', $phone)) {
            return null;
        }
        $international = str_starts_with($phone, '+');
        $digits = preg_replace('/\D/', '', $phone);
        if (! $international && preg_match('/^0[67][0-9]{8}$/', $digits)) {
            $digits = '255'.substr($digits, 1);
        } elseif (! $international && preg_match('/^[67][0-9]{8}$/', $digits)) {
            $digits = '255'.$digits;
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) ? '+'.$digits : null;
    }

    /** Return every matching account, including legacy profiles; duplicates require review. */
    public function matchingUserIds(string $phone): array
    {
        $ids = DB::table('phone_identities')->where('phone', $phone)->pluck('user_id')->all();
        foreach ($this->legacyPhoneRows() as $row) {
            if ($this->normalize((string) $row->phone) === $phone) {
                $ids[] = $row->id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function legacyPhoneRows(): array
    {
        $all = [];
        foreach ([['farmer', 'farmers', 'phone1'], ['farmInvitedUser', 'farm_users', 'phone'], ['systemUser', 'system_users', 'phone'], ['vet', 'system_users', 'phone']] as [$role, $table, $field]) {
            $rows = DB::table('users')->join($table, 'users.roleId', '=', $table.'.id')
                ->where('users.role', $role)->whereNotNull($table.'.'.$field)
                ->select('users.id', $table.'.'.$field.' as phone')->get();
            foreach ($rows as $row) {
                $all[] = $row;
            }
        }
        $officers = DB::table('users')->join('extension_officers', 'users.roleId', '=', 'extension_officers.id')
            ->where('users.role', 'extensionOfficer')->whereColumn('users.email', 'extension_officers.email')
            ->select('users.id', 'extension_officers.phone')->get();
        foreach ($officers as $row) {
            $all[] = $row;
        }
        $legacyOfficers = DB::table('users')->join('system_users', 'users.roleId', '=', 'system_users.id')
            ->where('users.role', 'extensionOfficer')->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('extension_officers')
                    ->whereColumn('extension_officers.id', 'users.roleId')->whereColumn('extension_officers.email', 'users.email');
            })->select('users.id', 'system_users.phone')->get();
        foreach ($legacyOfficers as $row) {
            $all[] = $row;
        }

        return $all;
    }

    public function hashCode(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.':'.$code, (string) config('app.key'));
    }

    public function pinMaterial(string $pin): string
    {
        return hash_hmac('sha256', $pin, (string) config('app.key'));
    }

    public function sendCode(string $phone, string $code): bool
    {
        $provider = config('services.phone_otp.provider');
        if ($provider === 'beem') {
            $key = config('services.beem_sms.api_key');
            $secret = config('services.beem_sms.secret_key');
            $sender = config('services.beem_sms.sender');
            if ($key && $secret && $sender) {
                return $this->sendBeemCode($phone, $code);
            }
            // Fall back to established SmsService when Beem is not configured
            return $this->sendLegacySmsCode($phone, $code);
        }
        if ($provider === 'sms_service') {
            return $this->sendLegacySmsCode($phone, $code);
        }
        if ($provider !== 'custom') {
            return $this->sendLegacySmsCode($phone, $code);
        }
        $url = config('services.phone_otp.url');
        $username = config('services.phone_otp.username');
        $password = config('services.phone_otp.password');
        $sender = config('services.phone_otp.sender');
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! $username || ! $password || ! $sender) {
            return false;
        }
        try {
            return Http::timeout(15)->post($url, [
                'username' => $username,
                'password' => $password,
                'senderId' => $sender,
                'message' => "Your Tag and Seal verification code is {$code}. It expires in 5 minutes. Do not share it.",
                'phoneNumbers' => [ltrim($phone, '+')],
            ])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function sendLegacySmsCode(string $phone, string $code): bool
    {
        try {
            $message = "Your Tag and Seal verification code is {$code}. It expires in 5 minutes. Do not share it.";
            $sms = app(SmsService::class);
            $result = $sms->sendSms($message, $phone);
            $this->lastGatewayResponse = $result;
            \Illuminate\Support\Facades\Log::info('📱 [OTP GATEWAY RESPONSE] '.$phone.': '.json_encode($result));
            if (is_array($result) && !isset($result['error'])) {
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('📱 [OTP GATEWAY EXCEPTION] '.$phone.': '.$e->getMessage());
            return false;
        }
    }

    private function sendBeemCode(string $phone, string $code): bool
    {
        $key = config('services.beem_sms.api_key');
        $secret = config('services.beem_sms.secret_key');
        $sender = config('services.beem_sms.sender');
        if (! $key || ! $secret || ! $sender) {
            return false;
        }
        try {
            $response = Http::timeout(15)->withBasicAuth($key, $secret)->post('https://apisms.beem.africa/v1/send', [
                'source_addr' => $sender,
                'encoding' => 0,
                'schedule_time' => '',
                'message' => "Your Tag and Seal verification code is {$code}. It expires in 5 minutes. Do not share it.",
                'recipients' => [['recipient_id' => '1', 'dest_addr' => ltrim($phone, '+')]],
            ]);

            return $response->successful() && $response->json('successful') === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
