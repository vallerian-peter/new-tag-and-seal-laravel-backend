<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    private const SMS_API_URL = 'http://155.12.30.77:8085/api/v1/send-sms';
    private const SMS_USERNAME = 'ShambaBora';
    private const SMS_PASSWORD = 'ShambaBora@2020';
    private const SMS_SENDER_ID = 'SHAMBA BORA'; // SMS sender ID (registered sender ID)

    /**
     * Send SMS to phone number(s)
     *
     * @param string $message
     * @param array|string $phoneNumbers Single phone number or array of phone numbers
     * @return array|string Returns response data on success, error message on failure
     */
    /**
     * Normalize phone number to Tanzania format (255XXXXXXXXX)
     * Handles various input formats: 0756473333, +255756473333, 255756473333, etc.
     *
     * @param string $phoneNumber
     * @return string|null Returns normalized phone number or null if invalid
     */
    private function normalizePhoneNumber(string $phoneNumber): ?string
    {
        // Remove all non-digit characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // If empty after cleaning, return null
        if (empty($phoneNumber)) {
            return null;
        }

        // Handle different formats
        // If starts with 255 (country code), use as is
        if (str_starts_with($phoneNumber, '255')) {
            return $phoneNumber;
        }

        // If starts with 0, replace with 255
        if (str_starts_with($phoneNumber, '0')) {
            return '255' . substr($phoneNumber, 1);
        }

        // If starts with +255, remove the + (already handled by preg_replace)
        // If it's 9-10 digits, assume it's a local number starting with 0
        if (strlen($phoneNumber) >= 9 && strlen($phoneNumber) <= 10) {
            if (!str_starts_with($phoneNumber, '0')) {
                return '255' . $phoneNumber;
            } else {
                return '255' . substr($phoneNumber, 1);
            }
        }

        // If already 12 digits and starts with 255, return as is
        if (strlen($phoneNumber) == 12 && str_starts_with($phoneNumber, '255')) {
            return $phoneNumber;
        }

        // Invalid format
        Log::warning("⚠️ Invalid phone number format: {$phoneNumber}");
        return null;
    }

    public function sendSms(string $message, $phoneNumbers): array|string
    {
        // Convert single phone number to array
        if (is_string($phoneNumbers)) {
            $phoneNumbers = [$phoneNumbers];
        }

        // Normalize and validate phone numbers
        $normalizedNumbers = [];
        foreach ($phoneNumbers as $phone) {
            $normalized = $this->normalizePhoneNumber(trim($phone));
            if ($normalized !== null) {
                $normalizedNumbers[] = $normalized;
            }
        }

        if (empty($normalizedNumbers)) {
            Log::warning('⚠️ SMS Service: No valid phone numbers provided after normalization');
            return 'No valid phone numbers provided';
        }

        $payload = [
            'username' => self::SMS_USERNAME,
            'password' => self::SMS_PASSWORD,
            'senderId' => self::SMS_SENDER_ID,
            'message' => $message,
            'phoneNumbers' => array_values($normalizedNumbers), // Re-index array
        ];

        try {
            Log::info("📱 Sending SMS to: " . implode(', ', $normalizedNumbers));
            Log::debug("📱 SMS Message: {$message}");
            Log::debug("📱 SMS Payload: " . json_encode($payload, JSON_PRETTY_PRINT));

            $response = Http::timeout(30)->post(self::SMS_API_URL, $payload);

            Log::debug("📱 SMS API Response Status: {$response->status()}");
            Log::debug("📱 SMS API Response Body: " . $response->body());

            if ($response->successful()) {
                Log::info("✅ SMS sent successfully to: " . implode(', ', $normalizedNumbers));
                $responseData = $response->json();
                Log::debug("📱 SMS Response Data: " . json_encode($responseData, JSON_PRETTY_PRINT));
                return $responseData ?? ['status' => 'success'];
            } else {
                $errorMessage = "Failed to send SMS. Status: {$response->status()}, Response: {$response->body()}";
                Log::error("❌ {$errorMessage}");
                return $errorMessage;
            }
        } catch (\Exception $e) {
            $errorMessage = "SMS sending error: " . $e->getMessage();
            Log::error("❌ {$errorMessage}");
            Log::error("❌ SMS Error Trace: " . $e->getTraceAsString());
            return $errorMessage;
        }
    }

    /**
     * Build welcome message for farm user invitation
     *
     * @param string $email
     * @param string $password
     * @param string $farmName
     * @param string $roleTitle
     * @param string|null $farmOwnerPhone
     * @param string|null $farmOwnerEmail
     * @return string
     */
    public function buildFarmUserWelcomeMessage(
        string $email,
        string $password,
        string $farmName,
        string $roleTitle,
        ?string $farmOwnerPhone = null,
        ?string $farmOwnerEmail = null
    ): string {
        $message = "Welcome to MyNg'ombe - Tag and Seal!\n\n";
        $message .= "You have been invited to join the farm: {$farmName}\n";
        $message .= "Role: {$roleTitle}\n\n";
        $message .= "Login Credentials:\n";
        $message .= "Email: {$email}\n";
        $message .= "Password: {$password}\n\n";
        $message .= "For more details, contact the farm owner:\n";
        
        if ($farmOwnerPhone) {
            $message .= "Phone: {$farmOwnerPhone}\n";
        }
        if ($farmOwnerEmail) {
            $message .= "Email: {$farmOwnerEmail}\n";
        }
        
        if (!$farmOwnerPhone && !$farmOwnerEmail) {
            $message .= "Contact details not available\n";
        }

        return $message;
    }

    /**
     * Build welcome message for farmer registration
     *
     * @param string $email
     * @param string $password
     * @param string $farmerName
     * @return string
     */
    public function buildFarmerWelcomeMessage(
        string $email,
        string $password,
        string $farmerName
    ): string {
        $message = "Welcome to MyNg'ombe - Tag and Seal, {$farmerName}!\n\n";
        $message .= "Your account has been successfully registered.\n\n";
        $message .= "Login Credentials:\n";
        $message .= "Email: {$email}\n";
        $message .= "Password: {$password}\n\n";
        $message .= "Thank you for joining us!";

        return $message;
    }
}

