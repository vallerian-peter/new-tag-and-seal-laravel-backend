<?php

namespace Tests\Feature\Admin;

use App\Models\Farm;
use App\Models\Farmer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommerceManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
        $this->seller = $this->farmer($country, 'Asha Seller', '0712345678', 'farm-s');
        $this->buyer = $this->farmer($country, 'Juma Buyer', '0712345679', 'farm-b');
        $type = DB::table('livestock_types')->insertGetId(['name' => 'Cattle']);
        $species = DB::table('species')->insertGetId(['name' => 'Bos taurus']);
        $breed = DB::table('breeds')->insertGetId(['name' => 'Friesian', 'group' => 'Dairy', 'livestockTypeId' => $type]);
        foreach ([1, 2] as $i) {
            DB::table('livestocks')->insert([
                'farmUuid' => 'farm-s', 'uuid' => "animal-$i", 'identificationNumber' => "TAG-$i",
                'livestockTypeId' => $type, 'name' => "Cow $i", 'dateOfBirth' => '2020-01-01',
                'gender' => 'female', 'breedId' => $breed, 'speciesId' => $species, 'status' => 'active',
            ]);
        }
        $this->actingAs(User::factory()->create(['platform_role' => 'superAdmin']));
    }

    public function test_admin_lists_a_farm_owners_animals_and_creates_a_listing_for_them(): void
    {
        $people = $this->getJson('/api/v1/admin/commerce/form/people')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Asha Seller', 'Juma Buyer'], array_column($people, 'name'));
        $this->getJson('/api/v1/admin/commerce/form/farms/farm-s/livestock')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.identification_number', 'TAG-1');

        $this->postJson('/api/v1/admin/commerce/listings', $this->listing(['seller_user_id' => $this->buyer->id]))
            ->assertUnprocessable()->assertJsonPath('message', 'The selected seller does not own this farm.');
        $this->postJson('/api/v1/admin/commerce/listings', $this->listing(['animal_uuids' => ['animal-1', 'animal-2']]))
            ->assertUnprocessable();

        $this->postJson('/api/v1/admin/commerce/listings', $this->listing())
            ->assertCreated()->assertJsonPath('data.seller_user_id', $this->seller->id)
            ->assertJsonPath('data.status', 'active');
        $this->assertSame('onSale', DB::table('livestocks')->where('uuid', 'animal-1')->value('status'));
        $this->assertDatabaseHas('animal_commerce_reservations', ['livestock_uuid' => 'animal-1', 'reservation_type' => 'marketplace_listing', 'status' => 'active']);
        $this->getJson('/api/v1/admin/commerce/form/farms/farm-s/livestock')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.uuid', 'animal-2');
        $this->postJson('/api/v1/admin/commerce/listings', $this->listing())->assertUnprocessable();
    }

    public function test_admin_places_an_order_for_a_buyer_with_the_same_marketplace_rules(): void
    {
        $listing = $this->postJson('/api/v1/admin/commerce/listings', $this->listing())->assertCreated()->json('data.uuid');
        $this->getJson('/api/v1/admin/commerce/form/open-listings')
            ->assertOk()->assertJsonPath('data.0.uuid', $listing)->assertJsonPath('data.0.animals.0.uuid', 'animal-1');

        $this->postJson('/api/v1/admin/commerce/orders', $this->order($listing, ['buyer_user_id' => $this->seller->id, 'destination_farm_uuid' => 'farm-s']))
            ->assertUnprocessable()->assertJsonPath('message', 'You cannot buy your own listing.');
        $this->postJson('/api/v1/admin/commerce/orders', $this->order($listing, ['destination_farm_uuid' => 'farm-s']))
            ->assertUnprocessable();

        $this->postJson('/api/v1/admin/commerce/orders', $this->order($listing))
            ->assertCreated()->assertJsonPath('data.buyer_user_id', $this->buyer->id)
            ->assertJsonPath('data.status', 'pending_settlement')->assertJsonPath('data.settlement_method', 'cash_on_delivery');
        $this->assertDatabaseHas('animal_commerce_reservations', ['livestock_uuid' => 'animal-1', 'reservation_type' => 'order_commitment', 'held_by_user_id' => $this->buyer->id, 'status' => 'active']);
        $this->assertSame(0, (int) DB::table('marketplace_listings')->where('uuid', $listing)->value('available_quantity'));
        $this->getJson('/api/v1/admin/commerce/form/open-listings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hold_keeps_an_animal_for_one_person_until_released_or_expired(): void
    {
        $other = User::factory()->create();
        $until = now()->addDays(3)->toDateTimeString();
        $this->postJson('/api/v1/admin/commerce/reservations', ['livestock_uuid' => 'animal-2', 'held_by_user_id' => $this->buyer->id, 'expires_at' => now()->addDays(31)->toDateTimeString()])
            ->assertUnprocessable()->assertJsonValidationErrors('expires_at');
        $hold = $this->postJson('/api/v1/admin/commerce/reservations', ['livestock_uuid' => 'animal-2', 'held_by_user_id' => $this->buyer->id, 'expires_at' => $until])
            ->assertCreated()->assertJsonPath('data.reservation_type', 'offer_hold')->json('data.uuid');
        $this->postJson('/api/v1/admin/commerce/reservations', ['livestock_uuid' => 'animal-2', 'held_by_user_id' => $other->id, 'expires_at' => $until])
            ->assertUnprocessable();
        $this->postJson('/api/v1/admin/commerce/listings', $this->listing(['animal_uuids' => ['animal-2']]))->assertUnprocessable();
        $this->getJson('/api/v1/admin/commerce/form/holdable-livestock')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/admin/commerce/reservations/$hold/release")->assertOk()->assertJsonPath('data.status', 'released');
        $this->postJson("/api/v1/admin/commerce/reservations/$hold/release")->assertUnprocessable();
        $this->postJson('/api/v1/admin/commerce/listings', $this->listing(['animal_uuids' => ['animal-2']]))->assertCreated();

        $listing = $this->postJson('/api/v1/admin/commerce/listings', $this->listing())->json('data.uuid');
        $held = $this->postJson('/api/v1/admin/commerce/reservations', ['livestock_uuid' => 'animal-1', 'held_by_user_id' => $other->id, 'expires_at' => $until])
            ->assertCreated()->json('data.uuid');
        $this->postJson('/api/v1/admin/commerce/orders', $this->order($listing))
            ->assertUnprocessable()->assertJsonPath('message', 'This animal is held for another buyer.');
        DB::table('animal_commerce_reservations')->where('uuid', $held)->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/admin/commerce/orders', $this->order($listing))->assertCreated();
        $this->assertDatabaseHas('animal_commerce_reservations', ['uuid' => $held, 'status' => 'expired']);
    }

    private function farmer(int $country, string $name, string $phone, string $farmUuid): User
    {
        $farmer = Farmer::create(['farmerNo' => "F-$farmUuid", 'firstName' => $name, 'surname' => 'X', 'phone1' => $phone, 'gender' => 'female', 'farmerType' => 'individual', 'countryId' => $country, 'status' => 'active']);
        $user = User::factory()->create(['role' => 'farmer', 'roleId' => $farmer->id]);
        DB::table('phone_identities')->insert(['user_id' => $user->id, 'phone' => '+255'.substr($phone, 1), 'display_name' => $name, 'pin_hash' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        Farm::create(['farmerId' => $farmer->id, 'uuid' => $farmUuid, 'referenceNo' => strtoupper($farmUuid), 'name' => "$name Farm", 'size' => 1, 'sizeUnit' => 'acre', 'physicalAddress' => 'Arusha', 'countryId' => $country, 'status' => 'active']);

        return $user;
    }

    private function listing(array $overrides = []): array
    {
        return array_merge([
            'seller_user_id' => $this->seller->id, 'farm_uuid' => 'farm-s', 'listing_title' => 'Cow 1',
            'listing_type' => 'individual', 'animal_uuids' => ['animal-1'], 'unit_price' => 900000,
        ], $overrides);
    }

    private function order(string $listing, array $overrides = []): array
    {
        return array_merge([
            'listing_uuid' => $listing, 'livestock_uuid' => 'animal-1', 'quantity' => 1,
            'buyer_user_id' => $this->buyer->id, 'destination_farm_uuid' => 'farm-b', 'settlement_method' => 'cash_on_delivery',
        ], $overrides);
    }
}
