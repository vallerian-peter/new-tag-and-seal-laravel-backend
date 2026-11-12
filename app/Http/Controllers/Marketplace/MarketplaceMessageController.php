<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;

use App\Models\Farmer;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceOffer;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Hiring\HiringNotifications;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketplaceMessageController extends Controller
{
    private function resolveUserContact(?User $user): array
    {
        if (! $user) {
            return ['id' => null, 'name' => 'Mfanyabiashara', 'phone' => ''];
        }
        $phone = DB::table('phone_identities')->where('user_id', $user->id)->value('phone')
            ?: ($user->phone ?? '');
        $name = trim((string) ($user->name ?? ''));
        if ($name === '' || str_starts_with(strtolower($name), 'user')) {
            if ($user->role === 'farmer' && $user->role_id) {
                $farmer = Farmer::find($user->role_id);
                if ($farmer) {
                    $farmerName = trim($farmer->firstName.' '.($farmer->middleName ?? '').' '.$farmer->surname);
                    if ($farmerName !== '') {
                        $name = preg_replace('/\s+/', ' ', $farmerName);
                    }
                    if ($phone === '' && ! empty($farmer->phone1)) {
                        $phone = $farmer->phone1;
                    }
                }
            }
        }

        return [
            'id' => (int) $user->id,
            'name' => $name !== '' ? $name : ($phone !== '' ? $phone : 'Mfanyabiashara'),
            'phone' => (string) $phone,
        ];
    }

    private function computeThreadKey(
        int $userA,
        int $userB,
        string $category,
        ?string $orderUuid,
        ?string $offerUuid,
        ?string $listingUuid,
        ?string $livestockUuid
    ): string {
        $minU = min($userA, $userB);
        $maxU = max($userA, $userB);
        $ref = $orderUuid ?: ($offerUuid ?: ($listingUuid ?: ($livestockUuid ?: 'general')));

        return "{$category}:{$ref}:{$minU}:{$maxU}";
    }

    /**
     * List all Buyer <-> Seller conversation threads for the authenticated user.
     * Categories are strictly aligned:
     * - 'order'   => Active Orders (waiting payment / payment submitted / ready for handover)
     * - 'offer'   => Price Offers & Negotiations
     * - 'inquiry' => Direct Marketplace Livestock Inquiries
     */
    public function conversations(Request $request): JsonResponse
    {
        $me = $request->user();
        $myId = (int) $me->id;

        $messages = MarketplaceMessage::with(['sender', 'recipient'])
            ->where(function ($q) use ($myId) {
                $q->where('sender_user_id', $myId)
                    ->orWhere('recipient_user_id', $myId);
            })
            ->orderByDesc('id')
            ->get();

        $threadsByKey = [];

        foreach ($messages as $msg) {
            $otherId = (int) $msg->sender_user_id === $myId ? (int) $msg->recipient_user_id : (int) $msg->sender_user_id;
            $key = $msg->thread_key.':'.$otherId;
            $otherUser = (int) $msg->sender_user_id === $myId ? $msg->recipient : $msg->sender;
            $contact = $this->resolveUserContact($otherUser);

            if (! isset($threadsByKey[$key])) {
                $threadsByKey[$key] = [
                    'thread_key' => $msg->thread_key,
                    'category' => $msg->category ?: 'inquiry',
                    'counterparty_user_id' => $contact['id'],
                    'counterparty_name' => $contact['name'],
                    'counterparty_phone' => $contact['phone'],
                    'listing_uuid' => $msg->listing_uuid,
                    'order_uuid' => $msg->order_uuid,
                    'offer_uuid' => $msg->offer_uuid,
                    'livestock_uuid' => $msg->livestock_uuid,
                    'topic' => $msg->topic ?: 'Mazungumzo ya Mfugo',
                    'last_message' => $msg->message,
                    'last_message_at' => $msg->created_at?->toIso8601String(),
                    'is_sent_by_me' => (int) $msg->sender_user_id === $myId,
                    'unread_count' => 0,
                ];
            }

            if ((int) $msg->recipient_user_id === $myId && $msg->read_at === null) {
                $threadsByKey[$key]['unread_count']++;
            }
        }

        $result = array_values($threadsByKey);
        usort($result, fn ($a, $b) => strcmp((string) ($b['last_message_at'] ?? ''), (string) ($a['last_message_at'] ?? '')));

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /**
     * Fetch messages for a specific conversation and mark incoming messages as read.
     */
    public function messages(Request $request): JsonResponse
    {
        $myId = (int) $request->user()->id;
        $threadKey = $request->query('thread_key');
        $orderUuid = $request->query('order_uuid');
        $offerUuid = $request->query('offer_uuid');
        $listingUuid = $request->query('listing_uuid');
        $livestockUuid = $request->query('livestock_uuid');
        $counterpartyId = $request->integer('counterparty_user_id');

        abort_unless($threadKey || $orderUuid || $offerUuid || $listingUuid || $livestockUuid, 422, 'A conversation reference is required.');

        if ($threadKey && ! $counterpartyId) {
            $parties = MarketplaceMessage::where('thread_key', $threadKey)
                ->where(fn ($q) => $q->where('sender_user_id', $myId)->orWhere('recipient_user_id', $myId))
                ->get(['sender_user_id', 'recipient_user_id'])
                ->map(fn ($row) => (int) ($row->sender_user_id == $myId ? $row->recipient_user_id : $row->sender_user_id))
                ->unique()->values();
            abort_unless($parties->count() <= 1, 422, 'Choose a conversation recipient.');
            $counterpartyId = $parties->first() ?? 0;
        }
        if (! $threadKey && ! $counterpartyId) {
            if ($orderUuid) {
                $order = PurchaseOrder::where('uuid', $orderUuid)->firstOrFail();
                abort_unless(in_array($myId, [(int) $order->buyer_user_id, (int) $order->seller_user_id], true), 403);
                $counterpartyId = (int) ($order->buyer_user_id == $myId ? $order->seller_user_id : $order->buyer_user_id);
            } elseif ($offerUuid) {
                $offer = MarketplaceOffer::with('listing')->where('uuid', $offerUuid)->firstOrFail();
                abort_unless($offer->listing && in_array($myId, [(int) $offer->buyer_user_id, (int) $offer->listing->seller_user_id], true), 403);
                $counterpartyId = (int) ($offer->buyer_user_id == $myId ? $offer->listing->seller_user_id : $offer->buyer_user_id);
            } elseif ($listingUuid) {
                $listing = MarketplaceListing::where('uuid', $listingUuid)->firstOrFail();
                abort_if((int) $listing->seller_user_id === $myId, 422, 'Choose a conversation recipient.');
                $counterpartyId = (int) $listing->seller_user_id;
            } else {
                abort(422, 'A conversation recipient is required.');
            }
        }

        $query = MarketplaceMessage::query()
            ->where(function ($q) use ($myId) {
                $q->where('sender_user_id', $myId)
                    ->orWhere('recipient_user_id', $myId);
            });

        if ($threadKey) {
            $query->where('thread_key', $threadKey);
        } else {
            if ($counterpartyId) {
                $query->where(function ($q) use ($counterpartyId) {
                    $q->where('sender_user_id', $counterpartyId)
                        ->orWhere('recipient_user_id', $counterpartyId);
                });
            }
            if ($orderUuid) {
                $query->where('order_uuid', $orderUuid)->where('category', 'order');
            } elseif ($offerUuid) {
                $query->where('offer_uuid', $offerUuid)->where('category', 'offer');
            } elseif ($listingUuid) {
                $query->where('listing_uuid', $listingUuid)->where('category', 'inquiry');
            } elseif ($livestockUuid) {
                $query->where('livestock_uuid', $livestockUuid)->where('category', 'inquiry');
            }
        }
        if ($counterpartyId && $threadKey) {
            $query->where(function ($q) use ($counterpartyId) {
                $q->where('sender_user_id', $counterpartyId)
                    ->orWhere('recipient_user_id', $counterpartyId);
            });
        }

        // Mark incoming unread messages as read
        (clone $query)
            ->where('recipient_user_id', $myId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $rows = $query->orderBy('id')->get()->map(function (MarketplaceMessage $msg) use ($myId) {
            return [
                'uuid' => $msg->uuid,
                'thread_key' => $msg->thread_key,
                'category' => $msg->category,
                'text' => $msg->message,
                'sent_by_me' => (int) $msg->sender_user_id === $myId,
                'status' => $msg->read_at ? 'read' : 'delivered',
                'created_at' => $msg->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Send a message between Buyer and Seller and dispatch a Swahili SMS alert to the recipient.
     */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'thread_key' => ['nullable', 'string', 'max:190'],
            'category' => ['nullable', 'in:order,offer,inquiry'],
            'listing_uuid' => ['nullable', 'string'],
            'order_uuid' => ['nullable', 'string'],
            'offer_uuid' => ['nullable', 'string'],
            'livestock_uuid' => ['nullable', 'string'],
            'recipient_user_id' => ['nullable', 'integer'],
            'topic' => ['nullable', 'string', 'max:255'],
            'reply_to_uuid' => ['nullable', 'string'],
        ]);

        $sender = $request->user();
        $senderId = (int) $sender->id;
        $recipientId = isset($data['recipient_user_id']) ? (int) $data['recipient_user_id'] : null;

        $orderUuid = $data['order_uuid'] ?? null;
        $offerUuid = $data['offer_uuid'] ?? null;
        $listingUuid = $data['listing_uuid'] ?? null;
        $livestockUuid = $data['livestock_uuid'] ?? null;
        $animalLabel = trim((string) ($data['topic'] ?? ''));

        if ($orderUuid) {
            $contextOrder = PurchaseOrder::where('uuid', $orderUuid)->firstOrFail();
            $parties = [(int) $contextOrder->buyer_user_id, (int) $contextOrder->seller_user_id];
            abort_unless(in_array($senderId, $parties, true), 403);
            $expectedRecipient = $parties[0] === $senderId ? $parties[1] : $parties[0];
            abort_if($recipientId && $recipientId !== $expectedRecipient, 403);
            $recipientId = $expectedRecipient;
        } elseif ($offerUuid) {
            $contextOffer = MarketplaceOffer::with('listing')->where('uuid', $offerUuid)->firstOrFail();
            abort_unless($contextOffer->listing, 404);
            $parties = [(int) $contextOffer->buyer_user_id, (int) $contextOffer->listing->seller_user_id];
            abort_unless(in_array($senderId, $parties, true), 403);
            $expectedRecipient = $parties[0] === $senderId ? $parties[1] : $parties[0];
            abort_if($recipientId && $recipientId !== $expectedRecipient, 403);
            $recipientId = $expectedRecipient;
        } elseif ($listingUuid) {
            $contextListing = MarketplaceListing::where('uuid', $listingUuid)->firstOrFail();
            if ((int) $contextListing->seller_user_id !== $senderId) {
                abort_if($recipientId && $recipientId !== (int) $contextListing->seller_user_id, 403);
                $recipientId = (int) $contextListing->seller_user_id;
            } else {
                abort_unless($recipientId && MarketplaceMessage::where('listing_uuid', $listingUuid)
                    ->where('category', 'inquiry')
                    ->where(function ($q) use ($senderId, $recipientId) {
                        $q->where('sender_user_id', $recipientId)->where('recipient_user_id', $senderId);
                    })->exists(), 403);
            }
        } else {
            abort(422, 'A marketplace conversation reference is required.');
        }
        abort_if($recipientId === $senderId, 422, 'Cannot message yourself.');
        $category = $data['category'] ?? ($orderUuid ? 'order' : ($offerUuid ? 'offer' : 'inquiry'));
        abort_unless($category === ($orderUuid ? 'order' : ($offerUuid ? 'offer' : 'inquiry')), 422, 'Category does not match the conversation.');
        if (! empty($data['thread_key'])) {
            $existingThread = MarketplaceMessage::where('thread_key', $data['thread_key'])
                ->where('category', $category)
                ->where(function ($q) use ($senderId, $recipientId) {
                    $q->where(fn ($pair) => $pair->where('sender_user_id', $senderId)->where('recipient_user_id', $recipientId))
                        ->orWhere(fn ($pair) => $pair->where('sender_user_id', $recipientId)->where('recipient_user_id', $senderId));
                });
            if ($orderUuid) {
                $existingThread->where('order_uuid', $orderUuid);
            } elseif ($offerUuid) {
                $existingThread->where('offer_uuid', $offerUuid);
            } else {
                $existingThread->where('listing_uuid', $listingUuid);
            }
            abort_unless($existingThread->exists(), 403);
        }

        // Resolve context and recipient from Order, Offer, or Listing
        if ($orderUuid) {
            $order = PurchaseOrder::with('items.livestock')->where('uuid', $orderUuid)->orWhere('order_code', $orderUuid)->first();
            if ($order) {
                $orderUuid = $order->uuid;
                $recipientId = $recipientId ?: ((int) $order->buyer_user_id === $senderId ? (int) $order->seller_user_id : (int) $order->buyer_user_id);
                $firstItem = $order->items->first();
                $listingUuid = $listingUuid ?: $firstItem?->listing_uuid;
                $livestockUuid = $livestockUuid ?: $firstItem?->livestock_uuid;
                if ($animalLabel === '' && $firstItem?->livestock) {
                    $animalLabel = "{$firstItem->livestock->name} ({$firstItem->livestock->identificationNumber})";
                }
            }
        }

        if ($offerUuid && ! $orderUuid) {
            abort_if($listingUuid && $listingUuid !== $contextOffer->listing_uuid, 422, 'Listing does not match the offer.');
            $listingUuid = $contextOffer->listing_uuid;
        }

        abort_if(! $recipientId, 422, 'Could not resolve recipient for this marketplace conversation.');

        $threadKey = ($data['thread_key'] ?? null) ?: $this->computeThreadKey(
            $senderId,
            $recipientId,
            $category,
            $orderUuid,
            $offerUuid,
            $listingUuid,
            $livestockUuid
        );
        if (! empty($data['reply_to_uuid'])) {
            abort_unless(MarketplaceMessage::where('uuid', $data['reply_to_uuid'])
                ->where('thread_key', $threadKey)
                ->where(function ($q) use ($senderId, $recipientId) {
                    $q->where(fn ($pair) => $pair->where('sender_user_id', $senderId)->where('recipient_user_id', $recipientId))
                        ->orWhere(fn ($pair) => $pair->where('sender_user_id', $recipientId)->where('recipient_user_id', $senderId));
                })->exists(), 422, 'Reply does not belong to this conversation.');
        }

        if ($animalLabel === '') {
            $animalLabel = 'Mfugo Sokoni';
        }

        $msg = MarketplaceMessage::create([
            'thread_key' => $threadKey,
            'category' => $category,
            'listing_uuid' => $listingUuid,
            'order_uuid' => $orderUuid,
            'offer_uuid' => $offerUuid,
            'livestock_uuid' => $livestockUuid,
            'sender_user_id' => $senderId,
            'recipient_user_id' => $recipientId,
            'topic' => $animalLabel,
            'message' => trim($data['message']),
            'reply_to_uuid' => $data['reply_to_uuid'] ?? null,
        ]);

        // Resolve sender and recipient details for Swahili SMS notification
        $senderContact = $this->resolveUserContact($sender);
        $recipientUser = User::find($recipientId);
        $recipientContact = $this->resolveUserContact($recipientUser);
        $recipientPhone = $recipientContact['phone'];

        $shortText = Str::limit($msg->message, 70);
        $swahiliSms = "Tag & Seal Soko: Una ujumbe mpya kuhusu mfugo wako sokoni ({$animalLabel}) kutoka kwa {$senderContact['name']}: \"{$shortText}\". Tafadhali ingia kwenye programu ya Tag & Seal kujibu.";

        $smsDispatched = false;
        try {
            app(HiringNotifications::class)->send(
                "marketplace.message.{$msg->uuid}",
                $recipientId,
                $recipientPhone !== '' ? $recipientPhone : null,
                'marketplace_message',
                $swahiliSms,
                $swahiliSms,
                [
                    'message_uuid' => $msg->uuid,
                    'thread_key' => $threadKey,
                    'category' => $category,
                    'listing_uuid' => $listingUuid,
                    'order_uuid' => $orderUuid,
                    'offer_uuid' => $offerUuid,
                ]
            );

            if ($recipientPhone !== '') {
                $smsResult = app(SmsService::class)->sendSms($swahiliSms, $recipientPhone);
                if (is_array($smsResult)) {
                    $msg->update(['sms_notified_at' => now()]);
                    $smsDispatched = true;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('⚠️ MarketplaceMessageController SMS alert warning: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => $smsDispatched ? 'Ujumbe umetumwa na taarifa ya SMS imetumwa kwa mhusika.' : 'Ujumbe umetumwa.',
            'sms_sent' => $smsDispatched,
            'sms_notice_sw' => $swahiliSms,
            'data' => [
                'uuid' => $msg->uuid,
                'thread_key' => $msg->thread_key,
                'category' => $msg->category,
                'text' => $msg->message,
                'sent_by_me' => true,
                'status' => 'sent',
                'created_at' => $msg->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * Delete a message sent by the authenticated user.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $msg = MarketplaceMessage::where('uuid', $uuid)->firstOrFail();
        abort_unless((int) $msg->sender_user_id === (int) $request->user()->id, 403, 'Unauthorized');
        $msg->delete();

        return response()->json([
            'success' => true,
            'message' => 'Ujumbe umefutwa.',
        ]);
    }
}
