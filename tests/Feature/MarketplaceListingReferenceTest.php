<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketplaceListingReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_browse_returns_livestock_reference_names(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $type = DB::table('livestock_types')->insertGetId(['name' => 'Cattle']);
        $species = DB::table('species')->insertGetId(['name' => 'Bos taurus']);
        $breed = DB::table('breeds')->insertGetId([
            'name' => 'Friesian', 'group' => 'Dairy', 'livestockTypeId' => $type,
        ]);
        DB::table('livestocks')->insert([
            'farmUuid' => 'farm-1', 'uuid' => 'animal-1', 'identificationNumber' => 'TAG-1',
            'livestockTypeId' => $type, 'name' => 'Daisy', 'dateOfBirth' => '2020-01-01',
            'gender' => 'female', 'breedId' => $breed, 'speciesId' => $species,
        ]);
        DB::table('marketplace_listings')->insert([
            'uuid' => 'listing-1', 'seller_user_id' => $seller->id, 'farm_uuid' => 'farm-1',
            'listing_title' => 'Daisy', 'listing_type' => 'individual', 'total_asking_price' => 100,
            'status' => 'active', 'total_quantity' => 1, 'available_quantity' => 1,
        ]);
        DB::table('marketplace_listing_items')->insert([
            'uuid' => 'item-1', 'listing_uuid' => 'listing-1', 'livestock_uuid' => 'animal-1',
            'allocated_asking_price' => 100,
        ]);

        $this->actingAs($buyer)->getJson('/api/v2/marketplace/listings')
            ->assertOk()
            ->assertJsonPath('data.0.items.0.livestock.name', 'Daisy')
            ->assertJsonPath('data.0.items.0.livestock.breed.name', 'Friesian')
            ->assertJsonPath('data.0.items.0.livestock.species.name', 'Bos taurus')
            ->assertJsonPath('data.0.items.0.livestock.livestock_type.name', 'Cattle')
            ->assertJsonPath('data.0.items.0.marketplace_available', true);

        DB::table('animal_commerce_reservations')->insert([
            'uuid' => 'reservation-1', 'livestock_uuid' => 'animal-1',
            'reservation_type' => 'order_commitment', 'reference_uuid' => 'order-1',
            'status' => 'active', 'held_by_user_id' => $buyer->id,
        ]);
        $this->getJson('/api/v2/marketplace/listings')
            ->assertOk()->assertJsonPath('data.0.items.0.marketplace_available', false);
    }
}
