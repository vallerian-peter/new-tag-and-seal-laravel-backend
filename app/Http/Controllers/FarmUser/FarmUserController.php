<?php

namespace App\Http\Controllers\FarmUser;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Mail\FarmUserInvitationMail;
use App\Models\FarmUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class FarmUserController extends Controller
{

    public function fetchByFarmUuids(array $farmUuids): array
    {
        if (empty($farmUuids)) {
            return [];
        }

        /** @var Collection<int, FarmUser> $farmUsers */
        // Handle both single UUID (string) and multiple UUIDs (JSON array)
        $farmUsers = FarmUser::where(function ($query) use ($farmUuids) {
            foreach ($farmUuids as $farmUuid) {
                $query->orWhere('farmUuid', $farmUuid) // Single UUID match
                      ->orWhereJsonContains('farmUuid', $farmUuid) // JSON array contains UUID
                      ->orWhere('farmUuid', 'LIKE', '%' . $farmUuid . '%'); // Fallback for JSON string
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

        Log::info("========== PROCESSING FARM USERS START ==========");
        Log::info("Total farm users to process: " . count($farmUsers));
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

                if (!$uuid) {
                    Log::warning('⚠️ Farm user without UUID skipped', ['farmUser' => $farmUserData]);
                    continue;
                }

                // Convert data types from Flutter to MySQL format
                $createdAt = isset($farmUserData['createdAt'])
                    ? \Carbon\Carbon::parse($farmUserData['createdAt'])->format('Y-m-d H:i:s')
                    : now();
                $updatedAt = isset($farmUserData['updatedAt'])
                    ? \Carbon\Carbon::parse($farmUserData['updatedAt'])->format('Y-m-d H:i:s')
                    : now();

                // Handle multiple farm UUIDs (supports both farmUuid and farmUuids)
                $farmUuids = [];
                if (isset($farmUserData['farmUuids']) && is_array($farmUserData['farmUuids'])) {
                    // New format: farmUuids array
                    $farmUuids = array_filter($farmUserData['farmUuids']);
                } elseif (isset($farmUserData['farmUuid']) && !empty($farmUserData['farmUuid'])) {
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
                            $localUpdatedAt = \Carbon\Carbon::parse($updatedAt);
                            $serverUpdatedAt = \Carbon\Carbon::parse($existingFarmUser->updated_at);

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
                                $existingFarmUser->updated_at = $updatedAt;
                                $existingFarmUser->save();
                                Log::info("✅ Farm user updated (local newer): {$existingFarmUser->email} (UUID: {$uuid})");

                                // Also update linked User account email (and keep status active)
                                $linkedUser = User::where('role', UserRole::FARM_INVITED_USER)
                                    ->where('roleId', $existingFarmUser->id)
                                    ->first();

                                if ($linkedUser) {
                                    $newEmail = $farmUserData['email'];
                                    $linkedUser->email = $newEmail;
                                    // Do not change password; keep same username
                                    $linkedUser->status = UserStatus::ACTIVE;
                                    $linkedUser->updatedBy = $createdByUserId;
                                    $linkedUser->save();

                                    Log::info("✅ Linked user updated for farm user: {$linkedUser->email} (User ID: {$linkedUser->id})");

                                    // Send (or re-send) invitation email with credentials
                                    try {
                                        $plainPassword = $newEmail; // As per requirement: password = email
                                        Mail::to($newEmail)->send(
                                            new FarmUserInvitationMail($existingFarmUser, $newEmail, $plainPassword)
                                        );
                                        Log::info("✅ Invitation email sent (existing farm user updated) to: {$newEmail}");
                                    } catch (\Throwable $mailException) {
                                        Log::warning("⚠️ Failed to send invitation email on existing farm user update to {$newEmail}: " . $mailException->getMessage());
                                    }
                                } else {
                                    Log::info("⏭️ No linked user found to update for farm user ID {$existingFarmUser->id}");
                                }
                            } else {
                                Log::info("⏭️ Farm user skipped (server newer): {$existingFarmUser->email} (UUID: {$uuid})");
                            }
                        } else {
                            // Farm user doesn't exist - create new
                            Log::info("Creating new farm user: {$farmUserData['email']} with " . count($farmUuids) . " farm(s)");

                            $farmUser = new FarmUser();
                            $farmUser->uuid = $uuid;
                            $farmUser->setFarmUuids($farmUuids); // Use setFarmUuids to handle JSON encoding
                            $farmUser->firstName = $farmUserData['firstName'];
                            $farmUser->middleName = $farmUserData['middleName'] ?? null;
                            $farmUser->lastName = $farmUserData['lastName'];
                            $farmUser->phone = $farmUserData['phone'] ?? null;
                            $farmUser->email = $farmUserData['email'];
                            $farmUser->roleTitle = $farmUserData['roleTitle'];
                            $farmUser->gender = $farmUserData['gender'];
                            $farmUser->created_at = $createdAt;
                            $farmUser->updated_at = $updatedAt;
                            $farmUser->save();

                            Log::info("✅ Farm user created successfully: {$farmUser->email} (ID: {$farmUser->id}, UUID: {$uuid})");

                            // Create User account for this farm user
                            $email = $farmUser->email;
                            $plainPassword = $email; // Password = email (as per requirement)

                            // Check if user already exists
                            $existingUser = User::where('email', $email)->first();

                            if (!$existingUser) {
                                // Username priority: lastname -> email prefix -> email
                                $username = '';

                                if (!empty($farmUser->lastName)) {
                                    $username = $farmUser->lastName;
                                } elseif (!empty($email) && str_contains($email, '@')) {
                                    $username = strstr($email, '@', true);
                                } else {
                                    $username = $email;
                                }

                                // Ensure username is unique
                                $baseUsername = $username;
                                $counter = 1;
                                while (User::where('username', $username)->exists()) {
                                    $username = $baseUsername . $counter;
                                    $counter++;
                                }

                                $user = User::create([
                                    'username' => $username,
                                    'email' => $email,
                                    'password' => Hash::make($plainPassword),
                                    'role' => UserRole::FARM_INVITED_USER,
                                    'roleId' => $farmUser->id,
                                    'status' => UserStatus::ACTIVE,
                                    'createdBy' => $createdByUserId, // Use User ID, not Farmer profile ID
                                    'updatedBy' => $createdByUserId, // Use User ID, not Farmer profile ID
                                ]);

                                Log::info("✅ User account created for farm user: {$user->email} (User ID: {$user->id})");

                                // Send invitation email with credentials
                                try {
                                    Mail::to($email)->send(
                                        new FarmUserInvitationMail($farmUser, $email, $plainPassword)
                                    );
                                    Log::info("✅ Invitation email sent to: {$email}");
                                } catch (\Throwable $mailException) {
                                    Log::warning("⚠️ Failed to send invitation email to {$email}: " . $mailException->getMessage());
                                    // Do not fail sync if email sending fails
                                }
                            } else {
                                Log::info("⏭️ User account already exists for email: {$email}");
                            }
                        }

                        $syncedFarmUsers[] = ['uuid' => $uuid];
                        break;

                    case 'update':
                        // Update existing farm user only if local is newer
                        $farmUser = FarmUser::where('uuid', $uuid)->first();

                        if ($farmUser) {
                            // Compare timestamps
                            $localUpdatedAt = \Carbon\Carbon::parse($updatedAt);
                            $serverUpdatedAt = \Carbon\Carbon::parse($farmUser->updated_at);

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
                                $farmUser->updated_at = $updatedAt;
                                $farmUser->save();
                                Log::info("✅ Farm user updated: {$farmUser->email} (UUID: {$uuid})");

                                // Also update linked User account and (re)send invitation email
                                $linkedUser = User::where('role', UserRole::FARM_INVITED_USER)
                                    ->where('roleId', $farmUser->id)
                                    ->first();

                                if ($linkedUser) {
                                    $newEmail = $farmUserData['email'];
                                    $linkedUser->email = $newEmail;
                                    $linkedUser->status = UserStatus::ACTIVE;
                                    $linkedUser->updatedBy = $createdByUserId;
                                    $linkedUser->save();

                                    Log::info("✅ Linked user updated (update action) for farm user: {$linkedUser->email} (User ID: {$linkedUser->id})");

                                    try {
                                        $plainPassword = $newEmail; // As per requirement: password = email
                                        Mail::to($newEmail)->send(
                                            new FarmUserInvitationMail($farmUser, $newEmail, $plainPassword)
                                        );
                                        Log::info("✅ Invitation email sent (update action) to: {$newEmail}");
                                    } catch (\Throwable $mailException) {
                                        Log::warning("⚠️ Failed to send invitation email on farm user update to {$newEmail}: " . $mailException->getMessage());
                                    }
                                } else {
                                    Log::info("⏭️ No linked user found to update for farm user (update action) ID {$farmUser->id}");
                                }

                                $syncedFarmUsers[] = ['uuid' => $uuid];
                            } else {
                                Log::info("⏭️ Farm user skipped (server newer): {$farmUser->email} (UUID: {$uuid})");
                            }
                        } else {
                            Log::warning("⚠️ Farm user not found for update: UUID={$uuid}");
                        }
                        break;

                    case 'deleted':
                        // Hard delete both farm user and linked user account
                        $farmUser = FarmUser::where('uuid', $uuid)->first();

                        if ($farmUser) {
                            Log::info("Deleting farm user: {$farmUser->email} (UUID: {$uuid})");

                            // Delete associated login user (if exists)
                            $user = User::where('role', UserRole::FARM_INVITED_USER)
                                ->where('roleId', $farmUser->id)
                                ->first();

                            if ($user) {
                                $userEmail = $user->email;
                                $userId = $user->id;
                                $user->delete();
                                Log::info("✅ Deleted user account for farm user: {$userEmail} (User ID: {$userId})");
                            } else {
                                Log::info("⏭️ No linked user account found for farm user ID {$farmUser->id}");
                            }

                            $farmUserEmail = $farmUser->email;
                            $farmUser->delete();
                            Log::info("✅ Farm user deleted: {$farmUserEmail} (UUID: {$uuid})");
                        } else {
                            Log::info("⏭️ Farm user already deleted on server: UUID {$uuid}");
                        }

                        $syncedFarmUsers[] = ['uuid' => $uuid];
                        break;

                    default:
                        Log::warning("⚠️ Unknown sync action: {$syncAction} for farm user UUID: {$uuid}");
                        break;
                }
            } catch (\Exception $e) {
                Log::error("❌ Error processing farm user: " . $e->getMessage(), [
                    'farmUserData' => $farmUserData,
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue processing other farm users
            }
        }

        Log::info("========== PROCESSING FARM USERS END ==========");
        Log::info("Total farm users synced: " . count($syncedFarmUsers));

        return $syncedFarmUsers;
    }
}


