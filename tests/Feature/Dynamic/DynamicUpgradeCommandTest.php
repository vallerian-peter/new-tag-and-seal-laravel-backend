<?php

namespace Tests\Feature\Dynamic;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicUpgradeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_dry_run_does_not_write_business_data(): void
    {
        $this->artisan('tag-and-seal:upgrade', ['release' => 'dynamic-v1', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('animal_species', 0);
        $this->assertDatabaseCount('platform_upgrade_runs', 0);
    }

    public function test_upgrade_is_checkpointed_and_resume_is_idempotent(): void
    {
        $this->artisan('tag-and-seal:upgrade', ['release' => 'dynamic-v1', '--backup' => 'test-snapshot'])
            ->assertSuccessful();

        $runUuid = (string) \DB::table('platform_upgrade_runs')->value('uuid');
        $this->assertDatabaseCount('animal_species', 8);
        $this->assertDatabaseHas('platform_upgrade_steps', ['run_uuid' => $runUuid, 'step_code' => 'backfill_events', 'state' => 'completed']);

        $this->artisan('tag-and-seal:upgrade', ['release' => 'dynamic-v1', '--resume' => $runUuid])
            ->assertSuccessful();
        $this->assertDatabaseCount('animal_species', 8);
        $this->assertDatabaseCount('platform_upgrade_runs', 1);
    }
}
