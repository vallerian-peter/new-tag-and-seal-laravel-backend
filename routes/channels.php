<?php

use App\Models\AuctionSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Live auction room (presence channel)
|--------------------------------------------------------------------------
| Anyone signed in may watch a live auction. Membership drives the viewer
| count; bids, lot changes and chat are broadcast here as `auction.event`.
*/
Broadcast::channel('auction.{uuid}', function ($user, string $uuid) {
    $session = AuctionSession::where('uuid', $uuid)->first();
    if (! $session || $session->status !== 'live') {
        return false;
    }

    return [
        'id' => (string) $user->id,
        'name' => $user->farmerCard()['name'] ?? $user->username,
        'isHost' => (int) $session->hostUserId === (int) $user->id,
    ];
});
