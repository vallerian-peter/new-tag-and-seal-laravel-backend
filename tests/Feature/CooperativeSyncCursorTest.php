<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Dairy\SyncCursor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CooperativeSyncCursorTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $farmer;

    private string $amcos;

    private string $centre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create();
        $this->farmer = User::factory()->create();
        $this->amcos = (string) Str::uuid();
        $this->centre = (string) Str::uuid();
        DB::table('cooperative_amcos')->insert(['uuid' => $this->amcos, 'amcos_code' => 'A1', 'name' => 'Cooperative']);
        DB::table('cooperative_collection_centres')->insert([
            'uuid' => $this->centre, 'amcos_uuid' => $this->amcos, 'centre_code' => 'C1', 'name' => 'Centre', 'default_unit' => 'l',
        ]);
        DB::table('cooperative_user_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $this->manager->id, 'amcos_uuid' => $this->amcos,
            'role' => 'amcos_admin', 'status' => 'approved',
        ]);
        DB::table('cooperative_farmer_memberships')->insert([
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $this->amcos, 'farmer_user_id' => $this->farmer->id,
            'member_number' => 'M0', 'farmer_name' => 'Farmer 0', 'farmer_phone' => '0712345670', 'status' => 'active',
        ]);
        $this->actingAs($this->manager);
    }

    private function intake(string $updatedAt, string $intakeAt = '2026-01-01 08:00:00'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('cooperative_intakes')->insert([
            'uuid' => $uuid, 'receipt_number' => $uuid, 'amcos_uuid' => $this->amcos, 'centre_uuid' => $this->centre,
            'farmer_user_id' => $this->farmer->id, 'gross_quantity' => 5, 'accepted_quantity' => 5,
            'intake_timestamp' => $intakeAt, 'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);

        return $uuid;
    }

    private function ledger(string $type, float $credit, float $debit, string $updatedAt = '2026-01-01 08:00:00'): void
    {
        DB::table('farmer_cooperative_account_ledgers')->insert([
            'uuid' => (string) Str::uuid(), 'amcos_uuid' => $this->amcos, 'farmer_user_id' => $this->farmer->id,
            'entry_type' => $type, 'credit_amount' => $credit, 'debit_amount' => $debit,
            'entry_date' => '2026-01-01 08:00:00', 'created_at' => $updatedAt, 'updated_at' => $updatedAt,
        ]);
    }

    public function test_intake_sync_pages_by_cursor_and_returns_only_changes(): void
    {
        $first = $this->intake('2026-01-01 08:00:00');
        $second = $this->intake('2026-01-01 08:00:00');
        $third = $this->intake('2026-01-02 08:00:00');

        $query = fn () => DB::table('cooperative_intakes as i')->where('i.amcos_uuid', $this->amcos);
        [$page, $meta] = SyncCursor::page($query(), 'i', null, 2);
        $this->assertSame([$first, $second], $page->pluck('uuid')->all());
        $this->assertTrue($meta['has_more']);
        [$page, $meta] = SyncCursor::page($query(), 'i', $meta['cursor'], 2);
        $this->assertSame([$third], $page->pluck('uuid')->all());
        $this->assertFalse($meta['has_more']);
        $this->assertSame('2026-01-02 07:59:55|0', $meta['cursor']);

        $url = '/api/v2/dairy/intakes?amcos_uuid='.$this->amcos.'&sync=1';
        $cursor = $this->getJson($url)->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.farmer_display_name', 'Farmer 0')
            ->assertJsonPath('meta.has_more', false)->json('meta.cursor');
        $this->getJson($url.'&cursor='.urlencode($cursor))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.cursor', $cursor);

        DB::table('cooperative_intakes')->where('uuid', $first)->update(['paid_amount' => 5, 'updated_at' => '2026-01-03 08:00:00']);
        $this->getJson($url.'&cursor='.urlencode($cursor))->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.1.uuid', $first);
    }

    public function test_ledger_summary_covers_every_entry_not_only_the_listed_page(): void
    {
        for ($i = 0; $i < 205; $i++) {
            $this->ledger('credit_delivery', 10, 0);
        }
        $this->ledger('debit_expense', 0, 30);
        $this->ledger('debit_payout', 0, 50);

        $url = '/api/v2/dairy/ledger?role=centre&amcos_uuid='.$this->amcos.'&farmer_user_id='.$this->farmer->id;
        $this->getJson($url)->assertOk()
            ->assertJsonCount(200, 'data.entries')
            ->assertJsonPath('data.summary.total_credits', 2050)
            ->assertJsonPath('data.summary.total_debits', 80)
            ->assertJsonPath('data.summary.total_expenses_debt', 30)
            ->assertJsonPath('data.summary.total_payouts', 50)
            ->assertJsonPath('data.summary.net_balance', 1970)
            ->assertJsonPath('data.entries.0.member_number', 'M0');

        $this->getJson('/api/v2/dairy/ledger?role=centre&sync=1&amcos_uuid='.$this->amcos)->assertOk()
            ->assertJsonCount(207, 'data.entries')
            ->assertJsonPath('meta.has_more', false);
    }
}
