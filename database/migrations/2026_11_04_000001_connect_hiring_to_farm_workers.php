<?php

use App\Models\FarmUser;
use App\Services\Hiring\WorkerPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_memberships', function (Blueprint $table) {
            $table->foreignId('farmer_id')->nullable()->constrained('farmers')->restrictOnDelete();
        });
        foreach (DB::table('feature_memberships')->where('feature', 'farmer')->get() as $membership) {
            $user = DB::table('users')->where('id', $membership->user_id)->first();
            if ($user && $user->role === 'farmer' && DB::table('farmers')->where('id', $user->roleId)->exists()) {
                DB::table('feature_memberships')->where('id', $membership->id)->update(['farmer_id' => $user->roleId]);
            }
        }
        Schema::table('career_jobs', function (Blueprint $table) {
            $table->unsignedInteger('positions_count')->default(1);
            $table->string('farm_uuid', 36)->nullable()->index();
        });
        foreach (DB::table('career_applications')->where('status', 'hired')->select('job_id')->distinct()->get() as $row) {
            DB::table('career_jobs')->where('id', $row->job_id)->update(['positions_count' => max(1, DB::table('career_applications')->where('job_id', $row->job_id)->where('status', 'hired')->count())]);
        }
        Schema::table('event_definitions', fn (Blueprint $table) => $table->string('access_module_code', 64)->nullable()->index());
        $modules = ['milking' => ['milk'], 'feeding' => ['feed', 'nutrition'], 'vaccination' => ['vaccin'], 'medication' => ['medicat', 'treatment'], 'deworming' => ['deworm'], 'weight-change' => ['weight'], 'birth-event' => ['birth'], 'aborted-pregnancy' => ['abort'], 'dryoff' => ['dryoff', 'dry_off'], 'insemination' => ['insemin'], 'pregnancy' => ['pregnan'], 'transfer' => ['transfer'], 'disposal' => ['dispos', 'death', 'slaughter'], 'finance' => ['income', 'expend', 'expense', 'finance']];
        foreach (DB::table('event_definitions')->get(['uuid', 'code', 'behavior_code']) as $definition) {
            foreach ($modules as $module => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains(strtolower($definition->code.' '.$definition->behavior_code), $needle)) {
                        DB::table('event_definitions')->where('uuid', $definition->uuid)->update(['access_module_code' => $module]);

                        continue 3;
                    }
                }
            }
        }
        Schema::create('career_onboardings', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('application_id')->nullable()->constrained('career_applications')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('farm_uuid', 36)->index();
            $table->string('source', 16)->default('career');
            $table->string('name');
            $table->string('phone', 32);
            $table->string('role_title', 100);
            $table->string('status', 32)->default('draft')->index();
            $table->json('terms');
            $table->json('permissions');
            $table->json('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('termination_reason', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('farm_worker_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_id')->unique()->constrained('career_onboardings')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('farm_uuid', 36)->index();
            $table->string('status', 32)->default('pending');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
        Schema::create('farm_access_grant_sources', function (Blueprint $table) {
            $table->id();
            $table->string('farm_uuid', 36);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('source_type', 24);
            $table->string('source_id', 64);
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['farm_uuid', 'user_id', 'source_type', 'source_id'], 'farm_grant_source_unique');
        });
        foreach (DB::table('farm_access_grants')->get() as $grant) {
            DB::table('farm_access_grant_sources')->insert(['farm_uuid' => $grant->farm_uuid, 'user_id' => $grant->user_id, 'source_type' => 'manual', 'source_id' => 'legacy', 'permissions' => $grant->permissions, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Only established role-to-profile links are trusted. Form-supplied contact
        // details never prove account identity or farm ownership.
        foreach (DB::table('users')->where('role', 'farmer')->whereNotNull('roleId')->get() as $owner) {
            if (! DB::table('farmers')->where('id', $owner->roleId)->exists()) {
                continue;
            }
            $membership = DB::table('feature_memberships')->where('user_id', $owner->id)->where('feature', 'farmer')->first();
            if ($membership) {
                DB::table('feature_memberships')->where('id', $membership->id)->update(['farmer_id' => $owner->roleId]);
            } else {
                DB::table('feature_memberships')->insert(['user_id' => $owner->id, 'feature' => 'farmer', 'farmer_id' => $owner->roleId, 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (FarmUser::all() as $profile) {
            $linked = DB::table('users')->where('role', 'farmInvitedUser')->where('roleId', $profile->id)->get();
            $user = $linked->count() === 1 ? $linked->first() : null;
            foreach ($profile->getFarmUuidsArray() as $farmUuid) {
                $farm = DB::table('farms')->where('uuid', $farmUuid)->first();
                if (! $farm) {
                    continue;
                }
                $owners = DB::table('feature_memberships')->where('feature', 'farmer')->where('farmer_id', $farm->farmerId)->pluck('user_id')->unique();
                if ($owners->count() !== 1) {
                    continue;
                }
                $owner = $owners->first();
                $active = $user && $profile->status === 'active' && $user->status === 'active';
                $permissions = ['farm.view'];
                if ($profile->roleTitle === 'farm-manager') {
                    $permissions = [...WorkerPermissions::catalog(), 'events.create'];
                } elseif (str_ends_with($profile->roleTitle, '-user')) {
                    $module = substr($profile->roleTitle, 0, -5);
                    if (in_array($module, WorkerPermissions::MODULES, true)) {
                        $permissions = ['farm.view', "work.$module.view", "work.$module.create"];
                    }
                }
                $grant = $user ? DB::table('farm_access_grants')->where('farm_uuid', $farmUuid)->where('user_id', $user->id)->first() : null;
                if ($grant) {
                    $permissions = json_decode($grant->permissions, true) ?: [];
                }
                if ($grant && ! in_array('farm.view', $permissions, true)) {
                    $active = false;
                }
                $id = DB::table('career_onboardings')->insertGetId(['request_uuid' => (string) Str::uuid(), 'owner_user_id' => $owner, 'user_id' => $user?->id, 'farm_uuid' => $farmUuid, 'source' => 'manual', 'name' => trim($profile->firstName.' '.$profile->lastName), 'phone' => $profile->phone ?? '', 'role_title' => $profile->roleTitle ?? 'worker', 'status' => $active ? 'activated' : 'draft', 'terms' => json_encode(['legacyFarmUserUuid' => $profile->uuid]), 'permissions' => json_encode($permissions), 'activated_at' => $active ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('farm_worker_assignments')->insert(['onboarding_id' => $id, 'user_id' => $user?->id, 'farm_uuid' => $farmUuid, 'status' => $active ? 'active' : 'pending', 'activated_at' => $active ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
                if ($active && ! $grant) {
                    DB::table('farm_access_grant_sources')->insert(['farm_uuid' => $farmUuid, 'user_id' => $user->id, 'source_type' => 'placement', 'source_id' => (string) $id, 'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('farm_access_grants')->insert(['farm_uuid' => $farmUuid, 'user_id' => $user->id, 'granted_by_user_id' => $owner, 'permissions' => json_encode($permissions), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        Schema::create('hiring_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('event_key', 160)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phone', 32)->nullable();
            $table->string('kind', 64);
            $table->json('payload');
            $table->text('message_en');
            $table->text('message_sw');
            $table->timestamp('read_at')->nullable();
            $table->string('sms_status', 24)->default('pending')->index();
            $table->unsignedTinyInteger('sms_attempts')->default(0);
            $table->timestamp('sms_next_attempt_at')->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hiring_notifications');
        Schema::dropIfExists('farm_access_grant_sources');
        Schema::dropIfExists('farm_worker_assignments');
        Schema::dropIfExists('career_onboardings');
        Schema::table('event_definitions', function (Blueprint $table) {
            $table->dropIndex(['access_module_code']);
            $table->dropColumn('access_module_code');
        });
        Schema::table('career_jobs', function (Blueprint $table) {
            $table->dropIndex(['farm_uuid']);
            $table->dropColumn(['positions_count', 'farm_uuid']);
        });
        Schema::table('feature_memberships', function (Blueprint $table) {
            $table->dropForeign(['farmer_id']);
            $table->dropColumn('farmer_id');
        });
    }
};
