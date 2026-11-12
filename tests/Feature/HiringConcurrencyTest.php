<?php

namespace Tests\Feature;

use App\Models\Farm;
use App\Models\Farmer;
use App\Models\User;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Hiring\GrantSources;
use App\Services\Hiring\OnboardingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class HiringConcurrencyTest extends TestCase
{
    public function test_two_concurrent_offers_cannot_reserve_the_last_position(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'tag_seal_hiring_concurrency_test_') || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires an isolated tag_seal_hiring_concurrency_test_* MySQL database and pcntl.');
        }
        $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();
        // Legacy migrations contain forward foreign-key references; all tables
        // exist before constraints are enforced for the actual race.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $country = DB::table('countries')->insertGetId(['name' => 'Test', 'shortName' => 'TT']);
        $farmer = Farmer::create(['farmerNo' => 'RACE', 'firstName' => 'Owner', 'surname' => 'Test', 'phone1' => '0712345678', 'gender' => 'male', 'farmerType' => 'individual', 'countryId' => $country, 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'farmer', 'roleId' => $farmer->id]);
        $farm = Farm::create(['farmerId' => $farmer->id, 'uuid' => (string) Str::uuid(), 'referenceNo' => 'RACE', 'name' => 'Test farm', 'size' => 1, 'sizeUnit' => 'acre', 'physicalAddress' => 'Test', 'countryId' => $country, 'status' => 'active']);
        DB::table('feature_memberships')->insert(['user_id' => $owner->id, 'feature' => 'jobOwner', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $job = DB::table('career_jobs')->insertGetId(['owner_user_id' => $owner->id, 'title' => 'Worker', 'positions_count' => 1, 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $applications = [];
        for ($i = 0; $i < 2; $i++) {
            $worker = User::factory()->create();
            $applications[] = DB::table('career_applications')->insertGetId(['job_id' => $job, 'applicant_user_id' => $worker->id, 'status' => 'reserve', 'applicant_snapshot' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $prefix = sys_get_temp_dir().'/hiring-race-'.Str::uuid();
        $start = microtime(true) + .3;
        DB::disconnect();
        $children = [];
        foreach ($applications as $index => $application) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                while (microtime(true) < $start) {
                    usleep(1000);
                }
                try {
                    app(OnboardingService::class)->send(User::findOrFail($owner->id), $application, ['requestUuid' => (string) Str::uuid(), 'farmUuid' => $farm->uuid, 'roleTitle' => 'Worker', 'permissions' => ['farm.view'], 'terms' => ['startDate' => '2026-12-01', 'responseDate' => '2026-11-30', 'reportingName' => 'Owner', 'reportingPhone' => '0712345678']]);
                    $result = 'reserved';
                } catch (ValidationException $error) {
                    $result = isset($error->errors()['positions']) ? 'full' : 'unexpected-validation';
                } catch (\Throwable $error) {
                    $result = get_class($error).': '.$error->getMessage();
                }
                file_put_contents($prefix.'-'.$index, $result);
                exit(0);
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::purge();
        $results = [];
        foreach ([0, 1] as $index) {
            $results[] = file_get_contents($prefix.'-'.$index);
            unlink($prefix.'-'.$index);
        }
        sort($results);
        $this->assertSame(['full', 'reserved'], $results);
        $this->assertSame(1, DB::table('career_onboardings')->where('status', 'sent')->count());
        $this->assertSame(1, app(OnboardingService::class)->occupied($job));
        $this->assertSame(1, DB::table('hiring_notifications')->count());
        $placement = DB::table('career_onboardings')->first();
        $worker = User::findOrFail($placement->user_id);
        DB::table('phone_identities')->insert(['user_id' => $worker->id, 'phone' => '+255712345679', 'pin_hash' => 'test-hash', 'created_at' => now(), 'updated_at' => now()]);
        $service = app(OnboardingService::class);
        $response = ['accept' => true, 'availability' => 'Available', 'phone' => '+255712345679', 'arrivalDate' => '2026-12-01'];
        $service->respond($worker, $placement->id, $response);
        $service->respond($worker, $placement->id, $response);
        $this->assertSame(1, DB::table('hiring_notifications')->where('kind', 'joining.response')->count());
        $owner = User::findOrFail($owner->id);
        $unchanged = $service->update($owner, $placement->id, ['requestUuid' => $placement->request_uuid, 'farmUuid' => $farm->uuid, 'roleTitle' => $placement->role_title, 'permissions' => json_decode($placement->permissions, true), 'terms' => json_decode($placement->terms, true)]);
        $this->assertSame('confirmed', $unchanged['status']);
        $service->activate($owner, $placement->id);
        $service->activate($owner, $placement->id);
        $this->assertSame(1, DB::table('farm_worker_assignments')->count());
        // Outer transactions may already have a REPEATABLE READ snapshot.
        // Both independent sources must survive concurrent projection updates.
        DB::disconnect();
        $children = [];
        $start = microtime(true) + .3;
        foreach (['feeding', 'milking'] as $module) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge();
                try {
                    DB::transaction(function () use ($owner, $farm, $worker, $module, $start) {
                        DB::table('farm_access_grants')->where('user_id', $worker->id)->get();
                        while (microtime(true) < $start) {
                            usleep(1000);
                        }
                        app(GrantSources::class)->write($farm->uuid, $worker->id, 'manual', $module, ['farm.view', "work.$module.view"], $owner->id);
                    });
                    file_put_contents($prefix.'-'.$module, 'ok');
                } catch (\Throwable $error) {
                    file_put_contents($prefix.'-'.$module, get_class($error));
                }
                exit(0);
            }
            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        DB::purge();
        foreach (['feeding', 'milking'] as $module) {
            $this->assertSame('ok', file_get_contents($prefix.'-'.$module));
            unlink($prefix.'-'.$module);
            $this->assertTrue(app(FarmAccessService::class)->canModule($worker, $farm->uuid, $module, 'view'));
        }

    }
}
