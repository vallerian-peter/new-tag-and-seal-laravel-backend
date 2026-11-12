<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Public lobby ticker: a session started, ended or changed. Clients re-list. */
class AuctionLobbyEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $kind, public array $data = [])
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('auction-lobby')];
    }

    public function broadcastAs(): string
    {
        return 'auction.lobby';
    }

    public function broadcastWith(): array
    {
        return ['kind' => $this->kind, 'data' => (object) $this->data, 'at' => now()->toIso8601String()];
    }
}
