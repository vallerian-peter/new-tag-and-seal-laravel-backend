<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dairy\BatchNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CooperativeDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private User $farmer;

    private string $amcos;

    private string $centre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clerk = User::factory()->create();
        $this->farmer = User::factory()->create();
        $this->amcos = (string) Str::uuid();
        $this->centre = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert([
            'uuid' => $this->amcos, 'amcos_code' => 'A1', 'name' => 'Cooperative',
        ]);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $this->centre, 'amcos_uuid' => $this->amcos, 'centre_code' => 'C1',
            'name' => 'Centre', 'default_unit' => 'l',
        ]);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->clerk->id, 'amcos_uuid' => $this->amcos,
            'role' => 'centre_clerk', 'status' => 'approved', 'assigned_centre_uuids' => json_encode([$this->centre]),
        ]);
        DB::table('cooperative_farmer_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $this->amcos, 'farmer_user_id' => $this->farmer->id,
            'member_number' => 'M1', 'farmer_name' => 'Farmer', 'farmer_phone' => '0712345678',
            'enrolled_centre_uuids' => json_encode([$this->centre]), 'status' => 'active',
        ]);
        $this->actingAs($this->clerk);
    }

    private function addPrice(string $amcos, string $startDate, float $price): void
    {
        DB::table('cooperative_seasons')->insert([
            'uuid' => (string) Str::uuid(), 'season_code' => (string) Str::uuid(), 'amcos_uuid' => $amcos,
            'name' => 'Price', 'unit' => 'l', 'start_date' => $startDate, 'price_per_unit' => $price, 'is_active' => true,
        ]);
    }

    private function delivery(): array
    {
        return [
            'uuid' => (string) Str::uuid(), 'centre_uuid' => $this->centre, 'farmer_user_id' => $this->farmer->id,
            'unit' => 'l', 'gross_quantity' => 10, 'accepted_quantity' => 8, 'rejected_quantity' => 2,
            'rejection_reason' => 'Quality', 'is_tested' => false,
        ];
    }

    public function test_receipt_updates_stock_and_farmer_read_and_notifies_once(): void
    {
        $this->addPrice($this->amcos, now()->subDay()->toDateString(), 500);
        $delivery = $this->delivery();
        $this->postJson('/api/v2/dairy/intakes', $delivery)->assertCreated()
            ->assertJsonPath('data.payable_amount', 4000);
        DB::table('cooperative_seasons')->where('amcos_uuid', $this->amcos)->update(['is_active' => false]);
        $this->postJson('/api/v2/dairy/intakes', $delivery)->assertOk();
        $this->postJson('/api/v2/dairy/intakes', [...$delivery, 'rejection_reason' => 'Changed'])->assertStatus(409);
        $this->assertDatabaseHas('cooperative_collection_centres', ['uuid' => $this->centre, 'current_balance' => 8]);
        $this->assertDatabaseCount('cooperative_intakes', 1);
        $this->assertDatabaseCount('hiring_notifications', 1);
        $this->assertDatabaseHas('hiring_notifications', ['user_id' => $this->farmer->id, 'kind' => 'cooperative_delivery_receipt']);

        $this->actingAs($this->farmer);
        $this->getJson('/api/v2/dairy/intakes?role=farmer')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.rejection_reason', 'Quality')
            ->assertJsonPath('data.0.farmer_display_name', 'Farmer');
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v2/dairy/intakes?role=farmer')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_receipt_requires_a_current_cooperative_price_and_matching_unit(): void
    {
        $this->addPrice((string) Str::uuid(), now()->subDay()->toDateString(), 1234);
        $this->addPrice($this->amcos, now()->addDay()->toDateString(), 500);
        $delivery = $this->delivery();
        $this->postJson('/api/v2/dairy/intakes', $delivery)->assertUnprocessable();
        $this->postJson('/api/v2/dairy/intakes', [...$delivery, 'unit' => 'kg'])->assertUnprocessable();
        $this->assertDatabaseCount('cooperative_intakes', 0);
    }

    public function test_receipt_records_the_goods_type_chosen_on_delivery(): void
    {
        $this->addPrice($this->amcos, now()->subDay()->toDateString(), 500);
        $milk = $this->delivery();
        $this->postJson('/api/v2/dairy/intakes', $milk)->assertCreated()
            ->assertJsonPath('data.commodity_code', 'milk');

        $this->postJson('/api/v2/dairy/intakes', [...$this->delivery(), 'commodity_code' => 'wool'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('commodity_code');
        $this->postJson('/api/v2/dairy/intakes', [...$milk, 'commodity_code' => 'wool'])->assertUnprocessable();
        $this->assertDatabaseCount('cooperative_intakes', 1);
    }

    public function test_tested_receipt_requires_real_results_without_assumed_passes(): void
    {
        $this->addPrice($this->amcos, now()->subDay()->toDateString(), 500);
        $delivery = [...$this->delivery(), 'is_tested' => true];
        $this->postJson('/api/v2/dairy/intakes', $delivery)->assertUnprocessable();
        $this->postJson('/api/v2/dairy/intakes', [...$delivery, 'fat_percentage' => 3.5])->assertCreated();
        $this->assertDatabaseHas('cooperative_intakes', [
            'uuid' => $delivery['uuid'], 'fat_percentage' => 3.5, 'alcohol_test_passed' => null, 'antibiotic_free' => null,
        ]);
    }

    public function test_stock_cannot_go_negative_and_retries_do_not_repeat_movement(): void
    {
        $this->addPrice($this->amcos, now()->subDay()->toDateString(), 500);
        $this->postJson('/api/v2/dairy/intakes', $this->delivery())->assertCreated();
        $movement = [
            'uuid' => (string) Str::uuid(), 'centre_uuid' => $this->centre,
            'movement_type' => 'outgoing_dispatch', 'quantity' => 5, 'destination_or_reason' => 'Sale',
        ];
        $this->postJson('/api/v2/dairy/centre-stock/movement', $movement)->assertCreated();
        $this->postJson('/api/v2/dairy/centre-stock/movement', $movement)->assertOk();
        $this->postJson('/api/v2/dairy/centre-stock/movement', [...$movement, 'quantity' => 6])->assertStatus(409);
        $this->postJson('/api/v2/dairy/centre-stock/movement', [...$movement, 'uuid' => (string) Str::uuid(), 'quantity' => 4])->assertUnprocessable();
        $this->assertDatabaseHas('cooperative_collection_centres', ['uuid' => $this->centre, 'current_balance' => 3]);
        $dispatch = DB::table('cooperative_dispatches')->where('uuid', $movement['uuid'])->first();
        $this->assertSame('dispatched', $dispatch->status);
        $this->assertMatchesRegularExpression(BatchNumber::PATTERN, $dispatch->batch_number);
        $this->assertNotNull($dispatch->loaded_at);

        $batch = [...$movement, 'uuid' => (string) Str::uuid(), 'quantity' => 1, 'batch_number' => 'BAT-0007-K7Q2M', 'loaded_at' => '2026-11-12T05:30:00Z'];
        $this->postJson('/api/v2/dairy/centre-stock/movement', $batch)->assertCreated();
        $this->assertDatabaseHas('cooperative_dispatches', ['uuid' => $batch['uuid'], 'batch_number' => 'BAT-0007-K7Q2M', 'loaded_at' => '2026-11-12 05:30:00']);

        // A clash (two offline phones) or a free-text number gets a fresh unique number.
        foreach (['BAT-0007-K7Q2M', 'B-07'] as $proposed) {
            $other = [...$movement, 'uuid' => (string) Str::uuid(), 'quantity' => 0.5, 'batch_number' => $proposed];
            $this->postJson('/api/v2/dairy/centre-stock/movement', $other)->assertCreated();
            $number = DB::table('cooperative_dispatches')->where('uuid', $other['uuid'])->value('batch_number');
            $this->assertMatchesRegularExpression(BatchNumber::PATTERN, $number);
            $this->assertNotSame('BAT-0007-K7Q2M', $number);
        }
    }

    public function test_each_goods_type_keeps_its_own_stock_at_the_centre(): void
    {
        $this->addPrice($this->amcos, now()->subDay()->toDateString(), 500);
        DB::table('cooperative_commodities')->where('code', 'wool')->update(['is_active' => true]);
        DB::table('cooperative_seasons')->insert([
            'uuid' => (string) Str::uuid(), 'season_code' => (string) Str::uuid(), 'amcos_uuid' => $this->amcos,
            'name' => 'Wool price', 'unit' => 'kg', 'start_date' => now()->subDay()->toDateString(),
            'price_per_unit' => 3000, 'is_active' => true,
        ]);

        $this->postJson('/api/v2/dairy/intakes', $this->delivery())->assertCreated();
        $wool = [...$this->delivery(), 'commodity_code' => 'wool'];
        unset($wool['unit']);
        $this->postJson('/api/v2/dairy/intakes', $wool)->assertCreated()
            ->assertJsonPath('data.unit', 'kg')
            ->assertJsonPath('data.payable_amount', 24000);
        $this->postJson('/api/v2/dairy/intakes', [...$this->delivery(), 'commodity_code' => 'wool'])->assertUnprocessable();

        $dispatch = fn (array $extra) => $this->postJson('/api/v2/dairy/centre-stock/movement', [
            'uuid' => (string) Str::uuid(), 'centre_uuid' => $this->centre,
            'movement_type' => 'outgoing_dispatch', 'destination_or_reason' => 'Factory', ...$extra,
        ]);
        $dispatch(['quantity' => 9, 'commodity_code' => 'wool'])->assertUnprocessable();
        $dispatch(['quantity' => 3, 'commodity_code' => 'wool'])->assertCreated()
            ->assertJsonPath('data.current_balance', 5);

        $this->assertDatabaseHas('cooperative_centre_stocks', ['centre_uuid' => $this->centre, 'commodity_code' => 'milk', 'unit' => 'l', 'current_balance' => 8]);
        $this->assertDatabaseHas('cooperative_centre_stocks', ['centre_uuid' => $this->centre, 'commodity_code' => 'wool', 'unit' => 'kg', 'current_balance' => 5, 'total_dispatched' => 3]);
        $this->assertDatabaseHas('cooperative_collection_centres', ['uuid' => $this->centre, 'current_balance' => 8, 'total_dispatched' => 0]);
        $this->assertDatabaseHas('cooperative_centre_stock_ledgers', ['centre_uuid' => $this->centre, 'commodity_code' => 'wool', 'unit' => 'kg', 'quantity' => -3]);

        DB::table('cooperative_user_memberships')->where('user_id', $this->clerk->id)->update(['role' => 'amcos_user', 'assigned_centre_uuids' => null]);
        $stocks = collect($this->getJson('/api/v2/dairy/hierarchy')->assertOk()->json('data.centres'))
            ->firstWhere('uuid', $this->centre)['stocks'];
        $this->assertEqualsCanonicalizing(['milk', 'wool'], array_column($stocks, 'commodity_code'));
    }

    public function test_ledger_entry_retry_is_idempotent_and_a_reused_uuid_is_a_conflict(): void
    {
        Http::fake();
        $entry = [
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $this->amcos, 'centre_uuid' => $this->centre,
            'farmer_user_id' => $this->farmer->id, 'entry_type' => 'debit_expense', 'amount' => 100,
            'description' => 'Feed',
        ];
        $this->postJson('/api/v2/dairy/ledger/entry', $entry)->assertSuccessful();
        $this->postJson('/api/v2/dairy/ledger/entry', $entry)->assertOk()->assertJsonPath('message', 'Entry already synced.');
        $this->postJson('/api/v2/dairy/ledger/entry', [...$entry, 'amount' => 250])->assertStatus(409);
        $this->assertDatabaseCount('farmer_cooperative_account_ledgers', 1);
    }

    public function test_hierarchy_gives_each_amcos_the_price_receipts_will_use(): void
    {
        $mcu = (string) Str::uuid();
        DB::table('cooperative_mcus')->insert(['uuid' => $mcu, 'mcu_code' => 'M1', 'name' => 'Union']);
        DB::table('cooperative_amcos')->where('uuid', $this->amcos)->update(['mcu_uuid' => $mcu]);
        $season = fn (array $row) => DB::table('cooperative_seasons')->insert([
            'uuid' => (string) Str::uuid(), 'season_code' => (string) Str::uuid(), 'name' => 'Price',
            'unit' => 'l', 'start_date' => now()->subDay()->toDateString(), 'is_active' => true, ...$row,
        ]);
        $season(['mcu_uuid' => $mcu, 'unit' => 'kg', 'name' => 'Union wool', 'price_per_unit' => 3000]);
        $season(['mcu_uuid' => $mcu, 'name' => 'Union milk', 'price_per_unit' => 900]);
        $season(['amcos_uuid' => $this->amcos, 'name' => 'Own milk', 'price_per_unit' => 1200]);
        $season(['amcos_uuid' => $this->amcos, 'name' => 'Next', 'price_per_unit' => 1500, 'start_date' => now()->addDay()->toDateString()]);

        $prices = collect($this->getJson('/api/v2/dairy/hierarchy')->assertOk()->json('data.amcos'))
            ->firstWhere('uuid', $this->amcos)['prices'];
        $this->assertEqualsCanonicalizing(['l', 'kg'], array_column($prices, 'unit'));
        $milk = collect($prices)->firstWhere('unit', 'l');
        $this->assertSame(['Own milk', 1200.0], [$milk['season_name'], (float) $milk['price_per_unit']]);
        $this->assertSame(3000.0, (float) collect($prices)->firstWhere('unit', 'kg')['price_per_unit']);

        $this->postJson('/api/v2/dairy/intakes', $this->delivery())->assertCreated()
            ->assertJsonPath('data.price_per_unit', fn ($v) => (float) $v === (float) $milk['price_per_unit']);
    }

    public function test_hierarchy_lists_the_mcu_drivers_with_their_company_and_truck(): void
    {
        $mcu = (string) Str::uuid();
        $otherMcu = (string) Str::uuid();
        DB::table('cooperative_mcus')->insert([
            ['uuid' => $mcu, 'mcu_code' => 'M1', 'name' => 'Union'],
            ['uuid' => $otherMcu, 'mcu_code' => 'M2', 'name' => 'Other union'],
        ]);
        DB::table('cooperative_amcos')->where('uuid', $this->amcos)->update(['mcu_uuid' => $mcu]);
        $fleet = function (string $company, ?string $mcuUuid, string $plate, string $name) {
            DB::table('transporter_companies')->insert([
                'uuid' => $company, 'name' => "{$name} Haulage", 'license_number' => $plate, 'contact_person' => $name,
                'phone' => '+255700000009', 'mcu_uuid' => $mcuUuid, 'approval_status' => 'approved', 'is_active' => true,
            ]);
            DB::table('transporter_vehicles')->insert([
                'uuid' => "{$company}-v", 'transporter_company_uuid' => $company, 'registration_number' => $plate,
                'tanker_capacity_liters' => 5000, 'approval_status' => 'approved', 'is_active' => true,
            ]);
            $user = User::factory()->create();
            DB::table('phone_identities')->insert([
                'user_id' => $user->id, 'phone' => '+2557'.random_int(10000000, 99999999), 'display_name' => $name,
                'pin_hash' => bcrypt('1234'),
            ]);
            DB::table('transporter_drivers')->insert([
                'uuid' => "{$company}-d", 'transporter_company_uuid' => $company, 'user_id' => $user->id,
                'driver_license_number' => $plate, 'assigned_vehicle_uuid' => "{$company}-v",
                'approval_status' => 'approved', 'is_active' => true,
            ]);
        };
        $fleet('tc-mine', $mcu, 'T 100 AAA', 'Juma');
        $fleet('tc-other', $otherMcu, 'T 200 BBB', 'Neema');

        $drivers = $this->getJson('/api/v2/dairy/hierarchy')->assertOk()->json('data.drivers');

        $this->assertCount(1, $drivers);
        $driver = $drivers[0];
        $this->assertSame(['Juma', true, 'T 100 AAA'], [$driver['name'], $driver['is_active'], $driver['assigned_vehicle_plate']]);
        $this->assertSame(['tc-mine', 'Juma Haulage'], [$driver['transporter']['uuid'], $driver['transporter']['name']]);
        $this->assertSame(['T 100 AAA', 5000.0], [$driver['vehicle']['registration_number'], (float) $driver['vehicle']['capacity']]);
        $this->assertNotEmpty($driver['phone']);
    }

    public function test_dispatch_list_shows_only_my_amcos_with_trip_phase_and_factory_decision(): void
    {
        $otherCentre = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => 'amcos-2', 'amcos_code' => 'A2', 'name' => 'Other']);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $otherCentre, 'amcos_uuid' => 'amcos-2', 'centre_code' => 'C2', 'name' => 'Other centre', 'default_unit' => 'l',
        ]);
        $row = fn (string $uuid, string $centre, array $extra = []) => DB::table('cooperative_dispatches')->insert([
            'uuid' => $uuid, 'dispatch_code' => strtoupper($uuid), 'batch_number' => "BAT-0001-{$uuid}", 'centre_uuid' => $centre,
            'commodity_code' => 'milk', 'quantity' => 40, 'unit' => 'l', 'destination_type' => 'factory',
            'destination_name' => 'Fresh Dairy', 'status' => 'dispatched', 'dispatched_at' => now(), ...$extra,
        ]);
        DB::table('dairy_trips')->insert([
            'uuid' => 'trip-1', 'trip_number' => 'TRP-1', 'commodity_code' => 'milk', 'transporter_company_uuid' => 'tc-1', 'driver_user_id' => $this->clerk->id, 'vehicle_uuid' => 'v-1', 'vehicle_plate_number' => 'T 1 A', 'destination_factory_uuid' => 'f-1', 'status' => 'in_transit', 'phase' => 'IN_TRANSIT',
            'started_at' => now(),
        ]);
        $row('MINE1', $this->centre, ['status' => 'on_trip', 'trip_uuid' => 'trip-1']);
        $row('MINE2', $this->centre);
        $row('THEIRS', $otherCentre);

        $rows = collect($this->getJson("/api/v2/dairy/dispatches?amcos_uuid={$this->amcos}")->assertOk()->json('data'))->keyBy('uuid');

        $this->assertEqualsCanonicalizing(['MINE1', 'MINE2'], $rows->keys()->all());
        $this->assertSame(['TRP-1', 'IN_TRANSIT'], [$rows['MINE1']['trip_number'], $rows['MINE1']['trip_phase']]);
        $this->assertNull($rows['MINE2']['trip_phase']);
        $this->assertSame('Centre', $rows['MINE2']['centre_name']);
    }

    public function test_receipt_credits_the_farmer_with_the_phone_ledger_uuid_once(): void
    {
        $this->addPrice($this->amcos, now()->subDay()->toDateString(), 500);
        $ledgerUuid = (string) Str::uuid();
        $delivery = [...$this->delivery(), 'ledger_uuid' => $ledgerUuid];
        $this->postJson('/api/v2/dairy/intakes', $delivery)->assertCreated();
        $this->postJson('/api/v2/dairy/intakes', $delivery)->assertOk();

        $this->assertDatabaseCount('farmer_cooperative_account_ledgers', 1);
        $this->assertDatabaseHas('farmer_cooperative_account_ledgers', [
            'uuid' => $ledgerUuid, 'entry_type' => 'credit_delivery', 'intake_uuid' => $delivery['uuid'], 'credit_amount' => 4000,
        ]);

        $reused = [...$this->delivery(), 'ledger_uuid' => $ledgerUuid];
        $this->postJson('/api/v2/dairy/intakes', $reused)->assertStatus(409);
        $this->assertDatabaseCount('cooperative_intakes', 1);
    }

    public function test_overpayment_becomes_debt_that_the_next_credit_reduces(): void
    {
        $this->addPrice($this->amcos, now()->subDay()->toDateString(), 500);
        $this->postJson('/api/v2/dairy/intakes', $this->delivery())->assertCreated();
        $payout = [
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $this->amcos, 'centre_uuid' => $this->centre,
            'farmer_user_id' => $this->farmer->id, 'entry_type' => 'debit_payout', 'amount' => 6000,
            'description' => 'Payment',
        ];
        $this->postJson('/api/v2/dairy/ledger/entry', $payout)->assertCreated()
            ->assertJsonPath('data.running_balance', -2000);
        $this->assertDatabaseHas('farmer_cooperative_accounts', [
            'farmer_user_id' => $this->farmer->id, 'balance' => -2000, 'outstanding_debt' => 2000,
        ]);

        $this->postJson('/api/v2/dairy/intakes', $this->delivery())->assertCreated();
        $this->assertDatabaseHas('farmer_cooperative_accounts', [
            'farmer_user_id' => $this->farmer->id, 'balance' => 2000, 'outstanding_debt' => 0,
        ]);
    }

    public function test_payout_sends_one_swahili_sms_naming_balance_or_debt(): void
    {
        $this->addPrice($this->amcos, now()->subDay()->toDateString(), 500);
        $this->postJson('/api/v2/dairy/intakes', $this->delivery())->assertCreated();
        $payout = fn (float $amount) => [
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $this->amcos, 'centre_uuid' => $this->centre,
            'farmer_user_id' => $this->farmer->id, 'entry_type' => 'debit_payout', 'amount' => $amount,
            'description' => 'Payment', 'reference_number' => 'PAY-1',
        ];
        $first = $payout(1000);
        $this->postJson('/api/v2/dairy/ledger/entry', $first)->assertCreated();
        $this->postJson('/api/v2/dairy/ledger/entry', $first)->assertOk();

        $sms = DB::table('hiring_notifications')->where('kind', 'cooperative_payout')->get();
        $this->assertCount(1, $sms);
        $this->assertSame("cooperative_payout:{$first['uuid']}", $sms[0]->event_key);
        $this->assertSame('Tag & Seal (PAY-1): Umelipwa TZS 1,000 na Cooperative. Salio jipya: TZS 3,000.', $sms[0]->message_sw);
        $this->assertSame($sms[0]->message_sw, $sms[0]->message_en);

        $this->postJson('/api/v2/dairy/ledger/entry', $payout(5000))->assertCreated();
        $this->assertDatabaseHas('hiring_notifications', [
            'kind' => 'cooperative_payout',
            'message_sw' => 'Tag & Seal (PAY-1): Umelipwa TZS 5,000 na Cooperative. Deni: TZS 2,000.',
        ]);
    }
}
