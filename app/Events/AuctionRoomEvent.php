<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Everything that happens inside a live auction room, except audio/video.
 * Broadcast immediately (no queue hop) on the room's presence channel.
 *
 * kinds: started, lot_opened, bid, lot_extended, lot_closed, chat, viewers,
 *        host_heartbeat, settings, ended
 */
class AuctionRoomEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionUuid,
        public string $kind,
        public array $data = [],
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PresenceChannel('auction.'.$this->sessionUuid)];
    }

    public function broadcastAs(): string
    {
        return 'auction.event';
    }

    public function broadcastWith(): array
    {
        return [
            'kind' => $this->kind,
            'data' => (object) $this->data,
            'at' => now()->toIso8601String(),
        ];
    }
}
