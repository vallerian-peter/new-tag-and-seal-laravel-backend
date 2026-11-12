<?php

namespace App\Services\Hiring;

use App\Models\User;
use App\Services\EmployerAccessService;
use App\Services\PhoneAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    public const OCCUPIED = ['sent', 'confirmed', 'activated'];

    public const TERMINAL = ['declined', 'cancelled', 'not_joined', 'ended'];

    public function __construct(private FarmOwnership $ownership, private GrantSources $grants, private HiringNotifications $notifications) {}

    public function occupied(int $jobId): int
    {
        $reserved = DB::table('career_onboardings')->join('career_applications', 'career_applications.id', '=', 'career_onboardings.application_id')->where('career_applications.job_id', $jobId)->whereIn('career_onboardings.status', self::OCCUPIED)->count();
        $legacy = DB::table('career_applications')->where('job_id', $jobId)->where('status', 'hired')->whereNotExists(function ($query) {
            $query->selectRaw('1')->from('career_onboardings')->whereColumn('application_id', 'career_applications.id')->whereIn('status', self::OCCUPIED);
        })->count();

        return $reserved + $legacy;
    }

    public function present(int $id): array
    {
        $row = (array) DB::table('career_onboardings')->where('id', $id)->first();
        foreach (['terms', 'permissions', 'response'] as $key) {
            $row[$key] = json_decode($row[$key] ?? 'null', true);
        }
        $row['farm_name'] = DB::table('farms')->where('uuid', $row['farm_uuid'])->value('name');
        $row['overdue'] = ($row['status'] === 'sent' && ! empty($row['terms']['responseDate']) && $row['terms']['responseDate'] < today()->toDateString()) || ($row['status'] === 'confirmed' && ! $row['arrived_at'] && ! empty($row['terms']['startDate']) && $row['terms']['startDate'] < today()->toDateString());

        return $row;
    }

    private function audit(User $actor, int $id, string $action, array $details = []): void
    {
        DB::table('audit_logs')->insert(['uuid' => (string) Str::uuid(), 'actor_uuid' => $actor->uuid, 'action' => 'hiring.'.$action, 'entity_type' => 'onboarding', 'entity_uuid' => (string) $id, 'after_json' => json_encode($details), 'request_uuid' => (string) Str::uuid(), 'created_at' => now()]);
    }

    public function send(User $actor, int $applicationId, array $data): array
    {
        // Resolve the parent outside the transaction: a plain read before waiting
        // for its lock would pin an old REPEATABLE READ snapshot in MySQL.
        $jobId = DB::table('career_applications')->where('id', $applicationId)->value('job_id') ?? abort(404);

        return DB::transaction(function () use ($actor, $applicationId, $data, $jobId) {
            $job = DB::table('career_jobs')->where('id', $jobId)->lockForUpdate()->first() ?? abort(404);
            $application = DB::table('career_applications')->where('id', $applicationId)->lockForUpdate()->first() ?? abort(404);
            abort_unless(app(EmployerAccessService::class)->can($actor, $job->owner_user_id, 'applications.manage'), 403);
            $owner = User::findOrFail($job->owner_user_id);
            abort_unless($this->ownership->owns($owner, $data['farmUuid']), 403);
            $prior = DB::table('career_onboardings')->where('request_uuid', $data['requestUuid'])->first();
            if ($prior) {
                abort_unless((int) $prior->application_id === $applicationId, 409);

                return $this->present($prior->id);
            }
            if (DB::table('career_onboardings')->where('application_id', $applicationId)->whereIn('status', self::OCCUPIED)->exists()) {
                throw ValidationException::withMessages(['application' => 'JOINING_ALREADY_IN_PROGRESS']);
            }
            if ($this->occupied($job->id) >= $job->positions_count && $application->status !== 'hired') {
                throw ValidationException::withMessages(['positions' => 'NO_POSITIONS_AVAILABLE']);
            }
            $permissions = WorkerPermissions::validate($data['permissions']);
            $profile = json_decode($application->applicant_snapshot, true) ?: [];
            $phone = DB::table('phone_identities')->where('user_id', $application->applicant_user_id)->value('phone') ?: ($profile['phone'] ?? '');
            $id = DB::table('career_onboardings')->insertGetId(['request_uuid' => $data['requestUuid'], 'application_id' => $applicationId, 'owner_user_id' => $owner->id, 'user_id' => $application->applicant_user_id, 'farm_uuid' => $data['farmUuid'], 'source' => 'career', 'name' => $profile['fullName'] ?? $profile['name'] ?? '', 'phone' => $phone, 'role_title' => $data['roleTitle'], 'status' => 'sent', 'terms' => json_encode($data['terms']), 'permissions' => json_encode($permissions), 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('career_jobs')->where('id', $job->id)->update(['farm_uuid' => $data['farmUuid'], 'updated_at' => now()]);
            DB::table('career_applications')->where('id', $applicationId)->update(['status' => 'accepted', 'updated_at' => now()]);
            $this->notifications->send("joining.$id.sent", $application->applicant_user_id, $phone, 'joining.sent', "Joining details for {$job->title} are ready. Open Tag & Seal to respond.", "Taarifa za kujiunga kwa {$job->title} zimetumwa. Fungua Tag & Seal kujibu.", ['onboardingId' => $id, 'applicationId' => $applicationId]);
            $this->audit($actor, $id, 'sent');

            return $this->present($id);
        });
    }

    public function update(User $actor, int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $row = DB::table('career_onboardings')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $row->owner_user_id === (int) $actor->id && $this->ownership->owns($actor, $row->farm_uuid), 403);
            abort_unless(in_array($row->status, ['sent', 'confirmed', 'draft'], true), 409);
            abort_unless($row->farm_uuid === $data['farmUuid'], 422);
            $permissions = WorkerPermissions::validate($data['permissions']);
            if (json_decode($row->terms, true) == $data['terms'] && json_decode($row->permissions, true) == $permissions && $row->role_title === $data['roleTitle']) {
                return $this->present($id);
            }
            DB::table('career_onboardings')->where('id', $id)->update(['terms' => json_encode($data['terms']), 'permissions' => json_encode($permissions), 'role_title' => $data['roleTitle'], 'status' => $row->source === 'career' ? 'sent' : 'draft', 'response' => null, 'responded_at' => null, 'updated_at' => now()]);
            $revision = (string) Str::uuid();
            $this->notifications->send("joining.$id.updated.$revision", $row->user_id, $row->phone, 'joining.updated', 'Your joining details have changed. Open Tag & Seal to review them.', 'Taarifa zako za kujiunga zimebadilishwa. Fungua Tag & Seal kuzikagua.', ['onboardingId' => $id]);
            $this->audit($actor, $id, 'updated');

            return $this->present($id);
        });
    }

    public function respond(User $actor, int $id, array $data): array
    {
        return DB::transaction(function () use ($actor, $id, $data) {
            $row = DB::table('career_onboardings')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless((int) $row->user_id === (int) $actor->id, 403);
            if (json_decode($row->response ?? 'null', true) == $data) {
                return $this->present($id);
            }
            abort_unless(in_array($row->status, ['sent', 'confirmed'], true), 409);
            $status = $data['accept'] ? 'confirmed' : 'declined';
            DB::table('career_onboardings')->where('id', $id)->update(['status' => $status, 'response' => json_encode($data), 'responded_at' => now(), 'updated_at' => now()]);
            if (! $data['accept'] && $row->application_id) {
                DB::table('career_applications')->where('id', $row->application_id)->update(['status' => 'offerDeclined', 'updated_at' => now()]);
            }
            $this->notifications->send("joining.$id.response.".(string) Str::uuid(), $row->owner_user_id, null, 'joining.response', "{$row->name} responded to joining details. Open Tag & Seal.", "{$row->name} amejibu taarifa za kujiunga. Fungua Tag & Seal.", ['onboardingId' => $id]);
            $this->audit($actor, $id, 'responded', ['status' => $status]);

            return $this->present($id);
        });
    }

    public function activate(User $actor, int $id): array
    {
        return DB::transaction(function () use ($actor, $id) {
            $row = DB::table('career_onboardings')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($this->ownership->owns($actor, $row->farm_uuid) && (int) $row->owner_user_id === (int) $actor->id, 403);
            if ($row->status === 'activated') {
                return $this->present($id);
            }
            abort_unless($row->source === 'manual' ? in_array($row->status, ['draft', 'confirmed']) : $row->status === 'confirmed', 409);
            $user = $row->user_id ? User::find($row->user_id) : null;
            if (! $user || ! $user->isActive() || ! DB::table('phone_identities')->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['user' => 'VERIFIED_WORKER_ACCOUNT_REQUIRED']);
            }
            abort_if($user->id === $actor->id, 422);
            $this->grants->write($row->farm_uuid, $user->id, 'placement', (string) $id, json_decode($row->permissions, true), $actor->id);
            DB::table('farm_worker_assignments')->updateOrInsert(['onboarding_id' => $id], ['user_id' => $user->id, 'farm_uuid' => $row->farm_uuid, 'status' => 'active', 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('career_onboardings')->where('id', $id)->update(['status' => 'activated', 'activated_at' => now(), 'updated_at' => now()]);
            if ($row->application_id) {
                DB::table('career_applications')->where('id', $row->application_id)->update(['status' => 'hired', 'updated_at' => now()]);
            }
            $this->notifications->send("joining.$id.activated", $user->id, $row->phone, 'access.activated', 'Your farm access is active. Open Tag & Seal to see your assigned duties.', 'Ruhusa zako za shamba zimewezeshwa. Fungua Tag & Seal kuona majukumu yako.', ['onboardingId' => $id]);
            $this->audit($actor, $id, 'activated');

            return $this->present($id);
        });
    }

    public function end(User $actor, int $id, string $status, string $reason): array
    {
        return DB::transaction(function () use ($actor, $id, $status, $reason) {
            $row = DB::table('career_onboardings')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($this->ownership->owns($actor, $row->farm_uuid) && (int) $row->owner_user_id === (int) $actor->id, 403);
            if (in_array($row->status, self::TERMINAL, true)) {
                return $this->present($id);
            }
            abort_unless($row->status === 'activated' ? $status === 'ended' : in_array($status, ['cancelled', 'not_joined'], true), 422);
            if ($row->user_id && $row->status === 'activated') {
                $this->grants->write($row->farm_uuid, $row->user_id, 'placement', (string) $id, [], $actor->id);
            }
            DB::table('farm_worker_assignments')->where('onboarding_id', $id)->update(['status' => 'ended', 'ended_at' => now(), 'updated_at' => now()]);
            DB::table('career_onboardings')->where('id', $id)->update(['status' => $status, 'termination_reason' => $reason, 'ended_at' => now(), 'updated_at' => now()]);
            if ($row->application_id) {
                DB::table('career_applications')->where('id', $row->application_id)->update(['status' => $status === 'ended' ? 'employmentEnded' : 'notJoined', 'updated_at' => now()]);
            }
            $this->notifications->send("joining.$id.$status", $row->user_id, $row->phone, 'placement.ended', 'Your farm placement has ended. Open Tag & Seal for details.', 'Mpango wako wa kazi shambani umesitishwa. Fungua Tag & Seal kwa taarifa.', ['onboardingId' => $id]);
            $this->audit($actor, $id, $status, ['reason' => $reason]);

            return $this->present($id);
        });
    }

    public function manual(User $owner, array $data, ?User $initiator = null): array
    {
        $initiator ??= $owner;
        abort_unless($initiator->is($owner) || $initiator->hasPlatformPermission('accounts.manage'), 403);
        abort_if(!$initiator->is($owner) && ($data['activate'] ?? false), 403);
        return DB::transaction(function () use ($owner, $data, $initiator) {
            abort_unless($this->ownership->owns($owner, $data['farmUuid']), 403);
            DB::table('farms')->where('uuid', $data['farmUuid'])->lockForUpdate()->first();
            $prior = DB::table('career_onboardings')->where('request_uuid', $data['requestUuid'])->first();
            if ($prior) {
                abort_unless((int) $prior->owner_user_id === (int) $owner->id && $prior->source === 'manual' && $prior->farm_uuid === $data['farmUuid'], 409);

                return $this->present($prior->id);
            }
            $phone = app(PhoneAuthentication::class)->normalize($data['phone']);
            if (! $phone) {
                throw ValidationException::withMessages(['phone' => 'INVALID_PHONE']);
            }
            $userId = DB::table('phone_identities')->where('phone', $phone)->value('user_id');
            abort_if($userId && (int) $userId === (int) $owner->id, 422);
            $id = DB::table('career_onboardings')->insertGetId(['request_uuid' => $data['requestUuid'], 'owner_user_id' => $owner->id, 'user_id' => $userId, 'farm_uuid' => $data['farmUuid'], 'source' => 'manual', 'name' => $data['name'], 'phone' => $phone, 'role_title' => $data['roleTitle'], 'status' => 'draft', 'terms' => json_encode($data['terms'] ?? []), 'permissions' => json_encode(WorkerPermissions::validate($data['permissions'])), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('farm_worker_assignments')->insert(['onboarding_id' => $id, 'user_id' => $userId, 'farm_uuid' => $data['farmUuid'], 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $this->notifications->send("worker.$id.registered", $userId, $phone, 'worker.registered', "You have been registered for farm work by {$owner->username}. Open Tag & Seal using this phone number to review your account.", "Umesajiliwa kwa kazi shambani na {$owner->username}. Fungua Tag & Seal kwa namba hii kukagua akaunti yako.", ['onboardingId' => $id]);
            $this->audit($initiator, $id, 'manual.registered');
            if (!$initiator->is($owner)) $this->notifications->send("worker.$id.owner-review", $owner->id, null, 'worker.pending', 'A pending worker registration needs your review. Open Tag & Seal to approve duties and activate access.', 'Usajili wa mfanyakazi unasubiri ukaguzi wako. Fungua Tag & Seal kuidhinisha majukumu na ruhusa.', ['onboardingId' => $id]);
            if (($data['activate'] ?? false) && $userId) {
                return $this->activate($owner, $id);
            }

            return $this->present($id);
        });
    }

    public function claim(User $user): void
    {
        $phone = DB::table('phone_identities')->where('user_id', $user->id)->value('phone');
        if (! $phone) {
            return;
        }
        DB::transaction(function () use ($phone, $user) {
            $ids = DB::table('career_onboardings')->whereNull('user_id')->where('phone', $phone)->where('source', 'manual')->where('status', 'draft')->lockForUpdate()->pluck('id');
            DB::table('career_onboardings')->whereIn('id', $ids)->update(['user_id' => $user->id, 'updated_at' => now()]);
            DB::table('farm_worker_assignments')->whereIn('onboarding_id', $ids)->update(['user_id' => $user->id, 'updated_at' => now()]);
            DB::table('hiring_notifications')->whereNull('user_id')->where('phone', $phone)->update(['user_id' => $user->id]);
        });
    }
}
