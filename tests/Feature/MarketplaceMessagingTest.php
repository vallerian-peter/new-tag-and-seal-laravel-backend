<?php

namespace Tests\Feature;

use App\Models\MarketplaceMessage;
use App\Models\User;
use App\Services\Hiring\HiringNotifications;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceMessagingTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $buyer;
    private User $otherBuyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = User::factory()->create();
        $this->buyer = User::factory()->create();
        $this->otherBuyer = User::factory()->create();
        DB::table('marketplace_listings')->insert([
            'uuid' => 'listing-1', 'seller_user_id' => $this->seller->id,
            'farm_uuid' => 'farm-1', 'listing_title' => 'Animal',
            'total_asking_price' => 100, 'status' => 'active',
        ]);
        DB::table('marketplace_offers')->insert([
            'uuid' => 'offer-1', 'listing_uuid' => 'listing-1',
            'buyer_user_id' => $this->buyer->id, 'offered_amount' => 90,
        ]);
        DB::table('purchase_orders')->insert([
            'uuid' => 'order-1', 'order_code' => 'ORD-1',
            'seller_user_id' => $this->seller->id, 'buyer_user_id' => $this->buyer->id,
            'origin_farm_uuid' => 'farm-1', 'total_amount' => 100,
            'source_type' => 'marketplace_direct', 'handover_challenge_hash' => 'hash',
        ]);
        $this->mock(HiringNotifications::class)->shouldReceive('send')->andReturnNull();
        $this->mock(SmsService::class)->shouldReceive('sendSms')->andReturn('Failed');
    }

    public function test_only_real_messages_create_conversations_and_reads_are_pair_scoped(): void
    {
        $this->actingAs($this->seller)->getJson('/api/v2/marketplace/conversations')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->seller)->getJson('/api/v2/marketplace/messages')
            ->assertUnprocessable();

        $first = $this->actingAs($this->buyer)->postJson('/api/v2/marketplace/messages', [
            'message' => 'First buyer', 'listing_uuid' => 'listing-1', 'category' => 'inquiry',
        ])->assertCreated();
        $second = $this->actingAs($this->otherBuyer)->postJson('/api/v2/marketplace/messages', [
            'message' => 'Second buyer', 'listing_uuid' => 'listing-1', 'category' => 'inquiry',
        ])->assertCreated();
        $this->assertNotSame($first->json('data.thread_key'), $second->json('data.thread_key'));
        $this->actingAs($this->seller)->getJson('/api/v2/marketplace/conversations')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.unread_count', 1);
        $thread = $first->json('data.thread_key');
        $this->getJson('/api/v2/marketplace/messages?'.http_build_query([
            'thread_key' => $thread, 'counterparty_user_id' => $this->buyer->id,
        ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.text', 'First buyer');
        $this->assertNotNull(MarketplaceMessage::where('uuid', $first->json('data.uuid'))->value('read_at'));
        $this->assertNull(MarketplaceMessage::where('uuid', $second->json('data.uuid'))->value('read_at'));
        $this->postJson('/api/v2/marketplace/messages', [
            'message' => 'Reply', 'listing_uuid' => 'listing-1', 'thread_key' => $thread,
            'recipient_user_id' => $this->buyer->id, 'reply_to_uuid' => $first->json('data.uuid'),
        ])->assertCreated();
        $this->actingAs($this->buyer)->getJson('/api/v2/marketplace/conversations')
            ->assertOk()->assertJsonPath('data.0.unread_count', 1)->assertJsonPath('data.0.last_message', 'Reply');
        $this->actingAs($this->otherBuyer)->getJson('/api/v2/marketplace/messages?'.http_build_query([
            'thread_key' => $thread,
        ]))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_legacy_shared_keys_are_separated_and_cannot_mark_other_parties_read(): void
    {
        foreach ([$this->buyer, $this->otherBuyer] as $buyer) {
            MarketplaceMessage::create([
                'thread_key' => 'old-shared-key', 'category' => 'inquiry',
                'listing_uuid' => 'listing-1', 'sender_user_id' => $buyer->id,
                'recipient_user_id' => $this->seller->id, 'message' => 'Hello '.$buyer->id,
            ]);
        }
        $this->actingAs($this->seller)->getJson('/api/v2/marketplace/conversations')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v2/marketplace/messages?thread_key=old-shared-key')
            ->assertUnprocessable();
        $this->getJson('/api/v2/marketplace/messages?'.http_build_query([
            'thread_key' => 'old-shared-key', 'counterparty_user_id' => $this->buyer->id,
        ]))->assertOk()->assertJsonCount(1, 'data');
        $this->assertNull(MarketplaceMessage::where('sender_user_id', $this->otherBuyer->id)->value('read_at'));
    }

    public function test_order_and_offer_messages_are_limited_to_their_participants(): void
    {
        foreach (['order' => 'order-1', 'offer' => 'offer-1'] as $category => $uuid) {
            $payload = ['message' => 'Hello', 'category' => $category, $category.'_uuid' => $uuid];
            $this->actingAs($this->otherBuyer)->postJson('/api/v2/marketplace/messages', $payload)
                ->assertForbidden();
            $this->actingAs($this->buyer)->postJson('/api/v2/marketplace/messages', $payload)
                ->assertCreated()->assertJsonPath('data.category', $category);
            $this->actingAs($this->seller)->getJson('/api/v2/marketplace/messages?'.http_build_query([
                $category.'_uuid' => $uuid,
            ]))->assertOk()->assertJsonCount(1, 'data');
        }
        $this->actingAs($this->buyer)->getJson('/api/v2/marketplace/messages?listing_uuid=listing-1')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->seller)->postJson('/api/v2/marketplace/messages', [
            'message' => 'Not an inquiry', 'listing_uuid' => 'listing-1',
            'recipient_user_id' => $this->otherBuyer->id,
        ])->assertForbidden();
    }
}
