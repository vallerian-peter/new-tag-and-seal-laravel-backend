<?php

namespace App\Services\Live;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * LiveKit is the MEDIA plane only. Room state, bids, chat and presence live in
 * Laravel + Reverb, so a media-server restart never loses auction state.
 * (Same approach as the Honu app.)
 */
class LiveKitService
{
    public function configured(): bool
    {
        return (bool) (config('services.livekit.api_key') && config('services.livekit.api_secret') && config('services.livekit.url'));
    }

    public function url(): ?string
    {
        return config('services.livekit.url') ?: null;
    }

    /**
     * Access token for a participant. Hosts publish; buyers only subscribe.
     * Data channels are disabled on purpose: the data plane is Reverb.
     */
    public function token(string $room, string $identity, string $name, bool $canPublish, int $ttlSeconds = 21600, array $metadata = []): string
    {
        $now = time();
        $payload = [
            'iss' => config('services.livekit.api_key'),
            'sub' => $identity,
            'jti' => $identity.'-'.$now,
            'nbf' => $now - 10,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'name' => $name,
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $canPublish,
                'canSubscribe' => true,
                'canPublishData' => false,
            ],
        ];
        if (! empty($metadata)) {
            $payload['metadata'] = json_encode($metadata);
        }

        return JWT::encode($payload, (string) config('services.livekit.api_secret'), 'HS256');
    }

    /** Server-to-server RoomService (twirp) call. Uses the INTERNAL url. */
    public function roomService(string $method, array $payload): ?array
    {
        return $this->twirp('livekit.RoomService', $method, $payload);
    }

    /** Server-to-server Egress (recording) call. Returns null when disabled or failing. */
    public function egress(string $method, array $payload): ?array
    {
        if (! config('services.livekit.egress_enabled')) {
            return null;
        }

        return $this->twirp('livekit.Egress', $method, $payload);
    }

    /**
     * Start a room-composite recording to the configured output. Returns the
     * egress id, or null if egress is not available.
     */
    public function startRoomRecording(string $room, string $fileName): ?string
    {
        $result = $this->egress('StartRoomCompositeEgress', [
            'room_name' => $room,
            'layout' => 'speaker',
            'file_outputs' => [[
                'file_type' => 'MP4',
                'filepath' => rtrim(config('services.livekit.egress_output'), '/').'/'.$fileName,
            ]],
        ]);

        return $result['egress_id'] ?? null;
    }

    public function stopRecording(string $egressId): bool
    {
        return $this->egress('StopEgress', ['egress_id' => $egressId]) !== null;
    }

    private function twirp(string $service, string $method, array $payload): ?array
    {
        $base = rtrim(config('services.livekit.internal_url') ?: 'http://127.0.0.1:7880', '/');
        try {
            $res = Http::withToken($this->adminToken())->timeout(5)
                ->post("$base/twirp/$service/$method", (object) $payload);
            if (! $res->successful()) {
                Log::warning('LiveKit twirp failed', ['service' => $service, 'method' => $method, 'status' => $res->status(), 'body' => $res->body()]);

                return null;
            }

            return $res->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('LiveKit twirp error', ['service' => $service, 'method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function adminToken(): string
    {
        $now = time();

        return JWT::encode([
            'iss' => config('services.livekit.api_key'),
            'sub' => 'tag-and-seal-backend',
            'nbf' => $now - 10,
            'iat' => $now,
            'exp' => $now + 60,
            'video' => ['roomAdmin' => true, 'roomCreate' => true, 'roomRecord' => true],
        ], (string) config('services.livekit.api_secret'), 'HS256');
    }

    /**
     * STUN/TURN servers for the client's RTCConfiguration. TURN credentials are
     * static here; switch to time-limited HMAC credentials when coturn runs in
     * use-auth-secret mode.
     */
    public function iceServers(): array
    {
        $servers = [];
        if ($stun = config('services.webrtc.stun')) {
            $servers[] = ['urls' => [$stun]];
        }
        if ($turn = config('services.webrtc.turn_url')) {
            $servers[] = [
                'urls' => [$turn.'?transport=udp', $turn.'?transport=tcp'],
                'username' => config('services.webrtc.turn_username'),
                'credential' => config('services.webrtc.turn_credential'),
            ];
        }

        return $servers;
    }
}
