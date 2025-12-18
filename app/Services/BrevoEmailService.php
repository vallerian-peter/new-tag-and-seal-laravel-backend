<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrevoEmailService
{
    /**
     * Base URL for Brevo v3 API.
     *
     * @var string
     */
    private string $baseUrl = 'https://api.brevo.com/v3';

    /**
     * Brevo API key.
     *
     * @var string
     */
    private string $apiKey;

    /**
     * Default sender name.
     *
     * @var string
     */
    private string $senderName;

    /**
     * Default sender email.
     *
     * @var string
     */
    private string $senderEmail;

    public function __construct()
    {
        $this->apiKey = config('services.brevo.api_key', env('BREVO_API_KEY', ''));
        $this->senderName = config('services.brevo.sender_name', env('BREVO_SENDER_NAME', 'Livestock - Tag and Seal'));
        $this->senderEmail = config('services.brevo.sender_email', env('BREVO_SENDER_EMAIL', 'vallerianmchau123456@gmail.com'));
    }

    /**
     * Create an email campaign in Brevo.
     *
     * @param  string               $name        Campaign name
     * @param  string               $subject     Email subject
     * @param  string               $htmlContent HTML body
     * @param  array<int, int>      $listIds     Brevo list IDs to send to
     * @param  string|null          $scheduledAt Optional schedule datetime (Y-m-d H:i:s, in UTC)
     * @return array|null
     */
    public function createEmailCampaign(
        string $name,
        string $subject,
        string $htmlContent,
        array $listIds,
        ?string $scheduledAt = null
    ): ?array {
        if (empty($this->apiKey)) {
            Log::warning('BrevoEmailService: BREVO_API_KEY is not configured.');
            return null;
        }

        $payload = [
            'name' => $name,
            'subject' => $subject,
            'sender' => [
                'name' => $this->senderName,
                'email' => $this->senderEmail,
            ],
            'type' => 'classic',
            'htmlContent' => $htmlContent,
            'recipients' => [
                'listIds' => array_values($listIds),
            ],
        ];

        if ($scheduledAt !== null) {
            $payload['scheduledAt'] = $scheduledAt;
        }

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'accept' => 'application/json',
        ])->post("{$this->baseUrl}/emailCampaigns", $payload);

        if (!$response->successful()) {
            Log::error('BrevoEmailService: Failed to create email campaign', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        Log::info('BrevoEmailService: Email campaign created successfully', [
            'campaignId' => $data['id'] ?? null,
        ]);

        return $data;
    }

    /**
     * Send a transactional email via Brevo.
     *
     * @param  string  $toEmail      Recipient email address
     * @param  string  $toName       Recipient name
     * @param  string  $subject      Email subject
     * @param  string  $htmlContent  HTML body content
     * @param  string|null  $textContent  Plain text body (optional)
     * @return bool
     */
    public function sendTransactionalEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlContent,
        ?string $textContent = null
    ): bool {
        if (empty($this->apiKey)) {
            Log::warning('BrevoEmailService: BREVO_API_KEY is not configured.');
            return false;
        }

        $payload = [
            'sender' => [
                'name' => $this->senderName,
                'email' => $this->senderEmail,
            ],
            'to' => [
                [
                    'email' => $toEmail,
                    'name' => $toName,
                ],
            ],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
        ];

        if ($textContent !== null) {
            $payload['textContent'] = $textContent;
        }

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->post("{$this->baseUrl}/smtp/email", $payload);

        if (!$response->successful()) {
            Log::error('BrevoEmailService: Failed to send transactional email', [
                'status' => $response->status(),
                'body' => $response->body(),
                'to' => $toEmail,
            ]);

            return false;
        }

        Log::info('BrevoEmailService: Transactional email sent successfully', [
            'to' => $toEmail,
            'messageId' => $response->json('messageId'),
        ]);

        return true;
    }
}


