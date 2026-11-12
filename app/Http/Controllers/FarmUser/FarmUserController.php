<?php

namespace App\Http\Controllers\FarmUser;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\FarmUser;
use App\Models\User;
use App\Services\Hiring\FarmOwnership;
use App\Services\Hiring\OnboardingService;
use App\Services\PhoneAuthentication;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FarmUserController extends Controller
{
    private SmsService $smsService;

    public function __construct()
    {
        $this->smsService = new SmsService;
    }

    public function fetchByFarmUuids(array $farmUuids): array
    {
        if (empty($farmUuids)) {
            return [];
        }

        /** @var Collection<int, FarmUser> $farmUsers */
        // Handle both single UUID (string) and multiple UUIDs (JSON array)
        $farmUsers = FarmUser::where(function ($query) use ($farmUuids) {
            foreach ($farmUuids as $farmUuid) {
                $query->orWhere(function ($q) use ($farmUuid) {
                    // Direct match for single UUID string
                    $q->where('farmUuid', $farmUuid)
                      // JSON array contains UUID (only if farmUuid is valid JSON)
                        ->orWhereRaw('JSON_VALID(farmUuid) = 1 AND JSON_CONTAINS(farmUuid, ?)', [json_encode($farmUuid)])
                      // LIKE match for partial string matches (handles both string and JSON string)
                        ->orWhere('farmUuid', 'LIKE', '%'.$farmUuid.'%');
                });
            }
        })
            ->orderBy('created_at', 'desc')
            ->get();

        return $farmUsers->map(function (FarmUser $farmUser) {
            $farmUuidsArray = $farmUser->getFarmUuidsArray();

            return [
                'id' => $farmUser->id,
                'uuid' => $farmUser->uuid,
                'farmUuid' => $farmUuidsArray[0] ?? null, // Legacy single farm support
                'farmUuids' => $farmUuidsArray, // Multiple farms array
                'firstName' => $farmUser->firstName,
                'middleName' => $farmUser->middleName,
                'lastName' => $farmUser->lastName,
                'phone' => $farmUser->phone,
                'email' => $farmUser->email,
                'roleTitle' => $farmUser->roleTitle,
                'gender' => $farmUser->gender,
                'createdAt' => $farmUser->created_at?->toIso8601String(),
                'updatedAt' => $farmUser->updated_at?->toIso8601String(),
            ];
        })->toArray();
    }

    public function processFarmUsers(array $farmUsers, int $farmerId, ?int $userId = null): array
    {
        $syncedFarmUsers = [];

        Log::info('========== PROCESSING FARM USERS START ==========');
        Log::info('Total farm users to process: '.count($farmUsers));
        Log::info("Authenticated Farmer ID: {$farmerId}");
        if ($userId) {
            Log::info("Authenticated User ID: {$userId}");
        }

        // Use User ID for createdBy/updatedBy (references users.id), fallback to farmerId if not provided
        $createdByUserId = $userId ?? $farmerId;

        foreach ($farmUsers as $farmUserData) {
            try {
                $syncAction = $farmUserData['syncAction'] ?? 'create';
                $uuid = $farmUserData['uuid'] ?? null;

                Log::info("Processing farm user: UUID={$uuid}, Action={$syncAction}, Email={$farmUserData['email']}");

                if (! $uuid) {
                    Log::warning('⚠️ Farm user without UUID skipped', ['farmUser' => $farmUserData]);

                    continue;
                }

                // Convert data types from Flutter to MySQL format
                $createdAt = isset($farmUserData['createdAt'])
                    ? Carbon::parse($farmUserData['createdAt'])->format('Y-m-d H:i:s')
                    : now();
                $updatedAt = isset($farmUserData['updatedAt'])
                    ? Carbon::parse($farmUserData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Handle multiple farm UUIDs (supports both farmUuid and farmUuids)
                $farmUuids = [];
                if (isset($farmUserData['farmUuids']) && is_array($farmUserData['farmUuids'])) {
                    // New format: farmUuids array
                    $farmUuids = array_filter($farmUserData['farmUuids']);
                } elseif (isset($farmUserData['farmUuid']) && ! empty($farmUserData['farmUuid'])) {
                    // Legacy format: single farmUuid (backward compatibility)
                    $farmUuids = [$farmUserData['farmUuid']];
                }

                if (empty($farmUuids)) {
                    Log::warning('⚠️ Farm user without farm UUID(s) skipped', ['farmUser' => $farmUserData]);

                    continue;
                }

                switch ($syncAction) {
                    case 'create':
                        // Check if farm user already exists
                        $existingFarmUser = FarmUser::where('uuid', $uuid)->first();

                        if ($existingFarmUser) {
                            // Farm user exists - check if local is newer
                            $localUpdatedAt = Carbon::parse($updatedAt);
                            $serverUpdatedAt = Carbon::parse($existingFarmUser->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                // Local is newer - update FarmUser profile
                                $existingFarmUser->setFarmUuids($farmUuids);
                                $existingFarmUser->firstName = $farmUserData['firstName'];
                                $existingFarmUser->middleName = $farmUserData['middleName'] ?? null;
                                $existingFarmUser->lastName = $farmUserData['lastName'];
                                $existingFarmUser->phone = $farmUserData['phone'] ?? null;
                                $existingFarmUser->email = $farmUserData['email'];
                                $existingFarmUser->roleTitle = $farmUserData['roleTitle'];
                                $existingFarmUser->gender = $farmUserData['gender'];
                                $existingFarmUser->status = 'active';
                                $existingFarmUser->updated_at = $updatedAt;
                                $existingFarmUser->save();
                                Log::info("✅ Farm user updated (local newer): {$existingFarmUser->email} (UUID: {$uuid})");

                                // Ensure linked User exists and is up to date (resend credentials)
                                $this->ensureUserAccount($existingFarmUser, $createdByUserId, null, true);
                            } else {
                                Log::info("⏭️ Farm user skipped profile update (server newer): {$existingFarmUser->email} (UUID: {$uuid})");
                                // Still ensure User account exists in users table even if profile was not updated
                                $this->ensureUserAccount($existingFarmUser, $createdByUserId, null, false);
                            }
                        } else {
                            // Farm user doesn't exist - create new
                            Log::info("Creating new farm user: {$farmUserData['email']} with ".count($farmUuids).' farm(s)');

                            $farmUser = new FarmUser;
                            $farmUser->uuid = $uuid;
                            $farmUser->setFarmUuids($farmUuids); // Use setFarmUuids to handle JSON encoding
                            $farmUser->firstName = $farmUserData['firstName'];
                            $farmUser->middleName = $farmUserData['middleName'] ?? null;
                            $farmUser->lastName = $farmUserData['lastName'];
                            $farmUser->phone = $farmUserData['phone'] ?? null;
                            $farmUser->email = $farmUserData['email'];
                            $farmUser->roleTitle = $farmUserData['roleTitle'];
                            $farmUser->gender = $farmUserData['gender'];
                            $farmUser->status = 'active';
                            $farmUser->created_at = $createdAt;
                            $farmUser->updated_at = $updatedAt;
                            $farmUser->save();

                            Log::info("✅ Farm user created successfully: {$farmUser->email} (ID: {$farmUser->id}, UUID: {$uuid})");

                            // Create User account for this farm user and send invitation
                            $this->ensureUserAccount($farmUser, $createdByUserId, $farmUser->email, true);
                        }

                        $syncedFarmUsers[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        // Update existing farm user only if local is newer
                        $farmUser = FarmUser::where('uuid', $uuid)->first();

                        if ($farmUser) {
                            // Compare timestamps
                            $localUpdatedAt = Carbon::parse($updatedAt);
                            $serverUpdatedAt = Carbon::parse($farmUser->updated_at);

                            if ($localUpdatedAt->greaterThan($serverUpdatedAt)) {
                                // Local is newer - perform update
                                $farmUser->setFarmUuids($farmUuids); // Use setFarmUuids to handle JSON encoding
                                $farmUser->firstName = $farmUserData['firstName'];
                                $farmUser->middleName = $farmUserData['middleName'] ?? null;
                                $farmUser->lastName = $farmUserData['lastName'];
                                $farmUser->phone = $farmUserData['phone'] ?? null;
                                $farmUser->email = $farmUserData['email'];
                                $farmUser->roleTitle = $farmUserData['roleTitle'];
                                $farmUser->gender = $farmUserData['gender'];
                                $farmUser->status = 'active';
                                $farmUser->updated_at = $updatedAt;
                                $farmUser->save();
                                Log::info("✅ Farm user updated: {$farmUser->email} (UUID: {$uuid})");

                                // Ensure linked User exists and is up to date (resend credentials)
                                $this->ensureUserAccount($farmUser, $createdByUserId, null, true);
                            } else {
                                Log::info("⏭️ Farm user skipped profile update (server newer): {$farmUser->email} (UUID: {$uuid})");
                                // Ensure User account exists even if profile wasn't updated
                                $this->ensureUserAccount($farmUser, $createdByUserId, null, false);
                            }

                            $syncedFarmUsers[] = ['uuid' => $uuid];
                        } else {
                            Log::warning("⚠️ Farm user not found for update: UUID={$uuid}");
                        }
                        break;

                    case 'deleted':
                        // End this employment only. Identity and other farm assignments survive.
                        $farmUser = FarmUser::where('uuid', $uuid)->first();
                        if ($farmUser) {
                            $actor = User::findOrFail($createdByUserId);
                            foreach (DB::table('career_onboardings')->where('source', 'manual')->where('owner_user_id', $actor->id)->get() as $placement) {
                                $terms = json_decode($placement->terms, true) ?: [];
                                if (($terms['legacyFarmUserUuid'] ?? null) === $uuid) {
                                    app(OnboardingService::class)->end($actor, $placement->id, $placement->status === 'activated' ? 'ended' : 'not_joined', 'Removed from farm workers');
                                }
                            }
                            // The profile is also used for login; employment access is revoked above.
                        }

                        $syncedFarmUsers[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action: {$syncAction} for farm user UUID: {$uuid}");
                        break;
                }
            } catch (\Exception $e) {
                Log::error('❌ Error processing farm user: '.$e->getMessage(), [
                    'farmUserData' => $farmUserData,
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue processing other farm users
            }
        }

        Log::info('========== PROCESSING FARM USERS END ==========');
        Log::info('Total farm users synced: '.count($syncedFarmUsers));

        return $syncedFarmUsers;
    }

    /**
     * Ensure a corresponding User record exists in `users` table for this FarmUser.
     * If missing, creates the User account with role=farmInvitedUser, roleId=farmUser->id,
     * status=active, and password=email (default per requirement).
     * If exists, ensures role, roleId, status, and email are aligned.
     */
    public function ensureUserAccount(FarmUser $farmUser, ?int $createdByUserId = null, ?string $overridePassword = null, bool $sendInvitationIfCreated = true): ?User
    {
        $linked = User::where('role', UserRole::FARM_INVITED_USER)->where('roleId', $farmUser->id)->first();
        if ($linked) {
            return $linked;
        } // Preserve legacy account identity, status and password.
        $phone = app(PhoneAuthentication::class)->normalize($farmUser->phone ?? '');
        $userId = $phone ? DB::table('phone_identities')->where('phone', $phone)->value('user_id') : null;
        $actor = $createdByUserId ? User::find($createdByUserId) : null;
        if ($actor && $phone) {
            foreach ($farmUser->getFarmUuidsArray() as $farm) {
                if (! app(FarmOwnership::class)->owns($actor, $farm)) {
                    continue;
                }
                $existing = DB::table('career_onboardings')->where('owner_user_id', $actor->id)->where('source', 'manual')->where('farm_uuid', $farm)->where('terms->legacyFarmUserUuid', $farmUser->uuid)->exists();
                if (! $existing) {
                    app(OnboardingService::class)->manual($actor, [
                        'requestUuid' => (string) Str::uuid(), 'farmUuid' => $farm,
                        'name' => trim($farmUser->firstName.' '.$farmUser->lastName), 'phone' => $phone,
                        'roleTitle' => $farmUser->roleTitle ?: 'worker', 'permissions' => ['farm.view'],
                        'terms' => ['legacyFarmUserUuid' => $farmUser->uuid],
                    ]);
                }
            }
        }

        return $userId ? User::find($userId) : null;
    }
}
