<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommerceDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $seller = User::factory()->create(['username' => 'commerce-seller', 'email' => 'seller@example.test']);
        $buyer = User::factory()->create(['username' => 'commerce-buyer', 'email' => 'buyer@example.test']);
        for ($i = 1; $i <= 7; $i++) {
            $created = sprintf('2026-01-%02d 09:00:00', $i);
            DB::table('marketplace_listings')->insert([
                'uuid' => 'listing-'.$i, 'seller_user_id' => $seller->id, 'farm_uuid' => 'farm-1',
                'listing_title' => 'Livestock lot '.$i, 'total_asking_price' => 100 * $i,
                'status' => $i === 7 ? 'sold' : 'active', 'created_at' => $created,
            ]);
            DB::table('animal_commerce_reservations')->insert([
                'uuid' => 'hold-'.$i, 'livestock_uuid' => 'animal-'.$i, 'reference_uuid' => 'listing-'.$i,
                'reservation_type' => 'marketplace_listing', 'held_by_user_id' => $buyer->id,
                'status' => $i === 7 ? 'fulfilled' : 'active', 'created_at' => $created,
            ]);
            DB::table('purchase_orders')->insert([
                'uuid' => 'order-'.$i, 'order_code' => 'ORD-'.$i, 'seller_user_id' => $seller->id,
                'buyer_user_id' => $buyer->id, 'origin_farm_uuid' => 'farm-1', 'total_amount' => 100 * $i,
                'handover_challenge_hash' => 'test-hash', 'source_type' => 'marketplace_direct', 'status' => $i === 7 ? 'transferred' : 'pending_settlement',
                'created_at' => $created,
            ]);
        }
        $this->actingAs(User::factory()->create(['platform_role' => 'superAdmin']));
    }

    public function test_search_and_status_filter_the_whole_registry_before_pagination(): void
    {
        foreach (['listings' => 'listing-1', 'reservations' => 'hold-1', 'orders' => 'order-1'] as $resource => $reference) {
            $this->getJson('/api/v1/admin/commerce/'.$resource.'?search='.$reference.'&per_page=5')
                ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.uuid', $reference);
        }
        $this->getJson('/api/v1/admin/commerce/listings?search=commerce-seller&status=sold')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.meta.total_listings', 7);
        $this->getJson('/api/v1/admin/commerce/reservations?search=commerce-buyer&status=fulfilled')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.meta.total_reservations', 7)
            ->assertJsonPath('data.meta.active_reservations', 6)->assertJsonPath('data.meta.fulfilled_reservations', 1);
        $this->getJson('/api/v1/admin/commerce/orders?search=buyer%40example.test&status=transferred')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.meta.pending_orders', 6);
    }

    public function test_sort_and_pagination_are_consistent_for_each_resource(): void
    {
        foreach (['listings' => 'listing-', 'reservations' => 'hold-', 'orders' => 'order-'] as $resource => $prefix) {
            $this->getJson('/api/v1/admin/commerce/'.$resource.'?sort=asc&per_page=5&page=2')
                ->assertOk()->assertJsonCount(2, 'data.data')->assertJsonPath('data.data.0.uuid', $prefix.'6')
                ->assertJsonPath('data.current_page', 2)->assertJsonPath('data.total', 7);
            $this->getJson('/api/v1/admin/commerce/'.$resource.'?sort=desc&per_page=5')
                ->assertOk()->assertJsonPath('data.data.0.uuid', $prefix.'7');
            $this->getJson('/api/v1/admin/commerce/'.$resource.'?search=no-match')
                ->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.data');
        }
    }

    public function test_invalid_filters_are_rejected_and_detail_endpoints_still_work(): void
    {
        $this->getJson('/api/v1/admin/commerce/orders?status=active')->assertUnprocessable();
        $this->getJson('/api/v1/admin/commerce/listings?sort=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/admin/commerce/reservations?per_page=1000')->assertUnprocessable();
        foreach (['listings' => 'listing-1', 'reservations' => 'hold-1', 'orders' => 'order-1'] as $resource => $reference) {
            $this->getJson('/api/v1/admin/commerce/'.$resource.'/'.$reference)
                ->assertOk()->assertJsonPath('data.uuid', $reference);
        }
    }
}
