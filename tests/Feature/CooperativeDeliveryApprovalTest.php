<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CooperativeDeliveryApprovalTest extends TestCase
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
        DB::table('cooperative_amcos')->insert(['uuid' => $this->amcos, 'amcos_code' => 'A1', 'name' => 'Cooperative']);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $this->centre, 'amcos_uuid' => $this->amcos, 'centre_code' => 'C1', 'name' => 'Centre', 'default_unit' => 'l',
        ]);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->clerk->id, 'amcos_uuid' => $this->amcos,
            'role' => 'centre_clerk', 'status' => 'approved',
        ]);
        DB::table('cooperative_farmer_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $this->amcos, 'farmer_user_id' => $this->farmer->id,
            'member_number' => 'M1', 'farmer_name' => 'Farmer', 'farmer_phone' => '0712345678',
            'enrolled_centre_uuids' => json_encode([]), 'status' => 'active',
        ]);
        DB::table('cooperative_seasons')->insert([
            'uuid' => (string) Str::uuid(), 'season_code' => 'S1', 'amcos_uuid' => $this->amcos, 'name' => 'Price',
            'unit' => 'l', 'start_date' => now()->subDay()->toDateString(), 'price_per_unit' => 1000, 'is_active' => true,
        ]);
    }

    private function declare(array $overrides = []): string
    {
        $uuid = (string) Str::uuid();
        $this->actingAs($this->farmer)->postJson('/api/v2/dairy/deliveries', array_merge([
            'uuid' => $uuid, 'centre_uuid' => $this->centre, 'declared_quantity' => 20,
            'delivery_session' => 'morning', 'is_tested' => true, 'temperature_celsius' => 6,
            'quality_metrics' => ['snf_percentage' => 8.5],
        ], $overrides))->assertCreated()->assertJsonPath('data.approval_status', 'pending');

        return $uuid;
    }

    private function approval(array $overrides = []): array
    {
        return array_merge([
            'review_uuid' => (string) Str::uuid(), 'action' => 'approve',
            'measurement_method' => 'weight', 'weight_kg' => 19.57,
            'gross_quantity' => 19, 'accepted_quantity' => 18, 'rejected_quantity' => 1, 'rejection_reason' => 'Spilled',
            'is_tested' => true, 'density_lactometer' => 28, 'fat_percentage' => 3.8, 'alcohol_test_passed' => true,
            'quality_grade' => 'A', 'quality_metrics' => ['protein_percentage' => 3.2, 'smell_normal' => true],
        ], $overrides);
    }

    public function test_farmer_declaration_waits_without_stock_or_credit_and_lists_for_staff(): void
    {
        $uuid = $this->declare();
        $this->postJson('/api/v2/dairy/deliveries', [
            'uuid' => $uuid, 'centre_uuid' => $this->centre, 'declared_quantity' => 20, 'is_tested' => false,
        ])->assertOk();
        $this->postJson('/api/v2/dairy/deliveries', [
            'uuid' => $uuid, 'centre_uuid' => $this->centre, 'declared_quantity' => 25, 'is_tested' => false,
        ])->assertStatus(409);

        $intake = DB::table('cooperative_intakes')->where('uuid', $uuid)->first();
        $this->assertSame('farmer', $intake->source);
        $this->assertSame(0.0, (float) $intake->accepted_quantity);
        $this->assertSame(['farmer_readings' => ['temperature_celsius' => 6, 'snf_percentage' => 8.5]], json_decode($intake->quality_metrics_json, true));
        $this->assertDatabaseCount('farmer_cooperative_account_ledgers', 0);
        $this->assertDatabaseCount('cooperative_centre_stock_ledgers', 0);

        $this->getJson('/api/v2/dairy/intakes?role=farmer')->assertOk()->assertJsonPath('data.0.approval_status', 'pending');
        $this->actingAs($this->clerk)->getJson("/api/v2/dairy/intakes?role=centre&amcos_uuid={$this->amcos}&approval_status=pending")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.declared_quantity', fn ($v) => (float) $v === 20.0);
        $this->getJson("/api/v2/dairy/intakes?role=centre&amcos_uuid={$this->amcos}&approval_status=approved")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_farmer_cannot_declare_at_another_cooperatives_centre_or_without_membership(): void
    {
        $otherAmcos = (string) Str::uuid();
        $otherCentre = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $otherAmcos, 'amcos_code' => 'A2', 'name' => 'Other']);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $otherCentre, 'amcos_uuid' => $otherAmcos, 'centre_code' => 'C2', 'name' => 'Other centre', 'default_unit' => 'l',
        ]);
        $this->actingAs($this->farmer)->postJson('/api/v2/dairy/deliveries', [
            'uuid' => (string) Str::uuid(), 'centre_uuid' => $otherCentre, 'declared_quantity' => 5, 'is_tested' => false,
        ])->assertStatus(422);
        $this->actingAs(User::factory()->create())->postJson('/api/v2/dairy/deliveries', [
            'uuid' => (string) Str::uuid(), 'centre_uuid' => $this->centre, 'declared_quantity' => 5, 'is_tested' => false,
        ])->assertStatus(422);
        $this->assertDatabaseCount('cooperative_intakes', 0);
    }

    public function test_approval_puts_milk_in_stock_credits_the_account_and_sends_sms_once(): void
    {
        $uuid = $this->declare();
        $this->actingAs($this->farmer)->postJson("/api/v2/dairy/intakes/{$uuid}/review", $this->approval())->assertForbidden();

        $ledgerUuid = (string) Str::uuid();
        $approval = $this->approval(['ledger_uuid' => $ledgerUuid]);
        $this->actingAs($this->clerk)->postJson("/api/v2/dairy/intakes/{$uuid}/review", $approval)->assertOk()
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.payable_amount', fn ($v) => (float) $v === 18000.0);
        $this->postJson("/api/v2/dairy/intakes/{$uuid}/review", $approval)->assertOk();
        $this->postJson("/api/v2/dairy/intakes/{$uuid}/review", $this->approval())->assertStatus(409);

        $intake = DB::table('cooperative_intakes')->where('uuid', $uuid)->first();
        $this->assertSame('weight', $intake->measurement_method);
        $this->assertSame('A', $intake->quality_grade);
        $metrics = json_decode($intake->quality_metrics_json, true);
        $this->assertSame(3.2, $metrics['protein_percentage']);
        $this->assertSame(6, $metrics['farmer_readings']['temperature_celsius']);
        $this->assertDatabaseHas('cooperative_centre_stocks', ['centre_uuid' => $this->centre, 'commodity_code' => 'milk', 'current_balance' => 18]);
        $this->assertDatabaseHas('farmer_cooperative_accounts', [
            'farmer_user_id' => $this->farmer->id, 'amcos_uuid' => $this->amcos, 'total_credit' => 18000, 'balance' => 18000,
        ]);
        $this->assertDatabaseCount('farmer_cooperative_account_ledgers', 1);
        $this->assertDatabaseHas('farmer_cooperative_account_ledgers', ['uuid' => $ledgerUuid, 'intake_uuid' => $uuid, 'entry_type' => 'credit_delivery']);
        $this->assertDatabaseHas('hiring_notifications', ['user_id' => $this->farmer->id, 'kind' => 'cooperative_delivery_approved']);
        $this->assertDatabaseCount('hiring_notifications', 1);
    }

    public function test_rejection_needs_a_reason_and_notifies_without_crediting(): void
    {
        $uuid = $this->declare();
        $this->actingAs($this->clerk)->postJson("/api/v2/dairy/intakes/{$uuid}/review", [
            'review_uuid' => (string) Str::uuid(), 'action' => 'reject',
        ])->assertStatus(422)->assertJsonValidationErrors('rejection_category');
        $this->postJson("/api/v2/dairy/intakes/{$uuid}/review", [
            'review_uuid' => (string) Str::uuid(), 'action' => 'reject', 'rejection_category' => 'other',
        ])->assertStatus(422)->assertJsonValidationErrors('rejection_reason');

        $this->postJson("/api/v2/dairy/intakes/{$uuid}/review", [
            'review_uuid' => (string) Str::uuid(), 'action' => 'reject', 'rejection_category' => 'below_grade',
            'rejection_reason' => 'Lactometer 24',
        ])->assertOk()->assertJsonPath('data.approval_status', 'rejected');
        $this->postJson("/api/v2/dairy/intakes/{$uuid}/review", $this->approval())->assertStatus(409);

        $this->assertDatabaseHas('cooperative_intakes', ['uuid' => $uuid, 'rejection_category' => 'below_grade', 'accepted_quantity' => 0]);
        $this->assertDatabaseCount('farmer_cooperative_account_ledgers', 0);
        $this->assertDatabaseHas('hiring_notifications', ['kind' => 'cooperative_delivery_rejected']);
        $this->assertStringContainsString('Lactometer 24', DB::table('hiring_notifications')->value('message_sw'));
    }

    public function test_account_totals_follow_the_ledger_through_receipts_and_payouts(): void
    {
        $this->actingAs($this->clerk)->postJson('/api/v2/dairy/intakes', [
            'uuid' => (string) Str::uuid(), 'centre_uuid' => $this->centre, 'farmer_user_id' => $this->farmer->id,
            'gross_quantity' => 10, 'accepted_quantity' => 10, 'rejected_quantity' => 0, 'is_tested' => false,
            'measurement_method' => 'volume',
        ])->assertCreated()->assertJsonPath('data.approval_status', 'approved');
        $this->postJson('/api/v2/dairy/ledger/entry', [
            'amcos_uuid' => $this->amcos, 'farmer_user_id' => $this->farmer->id, 'entry_type' => 'debit_payout',
            'amount' => 4000, 'description' => 'Cash',
        ])->assertCreated()->assertJsonPath('data.running_balance', fn ($v) => (float) $v === 6000.0);
        $this->postJson('/api/v2/dairy/ledger/entry', [
            'amcos_uuid' => $this->amcos, 'farmer_user_id' => $this->farmer->id, 'entry_type' => 'debit_payout',
            'amount' => 7000, 'description' => 'More than owed',
        ])->assertCreated()->assertJsonPath('data.running_balance', fn ($v) => (float) $v === -1000.0);

        $account = DB::table('farmer_cooperative_accounts')->first();
        $this->assertSame([10000.0, 11000.0, -1000.0, 1000.0], [
            (float) $account->total_credit, (float) $account->total_debit, (float) $account->balance, (float) $account->outstanding_debt,
        ]);
        $this->assertSame(3, DB::table('farmer_cooperative_account_ledgers')->where('account_uuid', $account->uuid)->count());
        $this->actingAs($this->farmer)->getJson('/api/v2/dairy/ledger')->assertOk()
            ->assertJsonPath('data.account.balance', fn ($v) => (float) $v === -1000.0)
            ->assertJsonPath('data.summary.net_balance', -1000);
    }

    public function test_farmer_hierarchy_lists_the_centres_of_his_cooperative(): void
    {
        $this->actingAs($this->farmer)->getJson('/api/v2/dairy/hierarchy')->assertOk()
            ->assertJsonPath('data.centres.0.uuid', $this->centre);
    }
}
