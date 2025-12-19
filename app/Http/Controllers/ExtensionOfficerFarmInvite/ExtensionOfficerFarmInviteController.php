<?php

namespace App\Http\Controllers\ExtensionOfficerFarmInvite;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Mail\ExtensionOfficerInvitationMail;
use App\Models\ExtensionOfficer;
use App\Models\ExtensionOfficerFarmInvite;
use App\Models\Farm;
use App\Models\Farmer;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class ExtensionOfficerFarmInviteController extends Controller
{
    private SmsService $smsService;

    public function __construct()
    {
        $this->smsService = new SmsService;
    }

    /**
     * Search for extension officer by email
     */
    public function searchByEmail(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $email = $request->input('email');
            $extensionOfficer = ExtensionOfficer::where('email', $email)->first();

            if (! $extensionOfficer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Extension officer not found with this email',
                    'data' => null,
                ], 404);
            }

            // Build officer payload (excluding sensitive fields)
            $officerPayload = [
                'id' => $extensionOfficer->id,
                'firstName' => $extensionOfficer->firstName,
                'middleName' => $extensionOfficer->middleName,
                'lastName' => $extensionOfficer->lastName,
                'email' => $extensionOfficer->email,
                'phone' => $extensionOfficer->phone,
                'specialization' => $extensionOfficer->specialization,
                'organization' => $extensionOfficer->organization,
                'countryId' => $extensionOfficer->countryId,
                'regionId' => $extensionOfficer->regionId,
                'districtId' => $extensionOfficer->districtId,
                'wardId' => $extensionOfficer->wardId,
                'isVerified' => $extensionOfficer->isVerified == 1 ? true : false,
                'created_at' => $extensionOfficer->created_at ? $extensionOfficer->created_at->toDateTimeString() : null,
                'updated_at' => $extensionOfficer->updated_at ? $extensionOfficer->updated_at->toDateTimeString() : null,
            ];

            return response()->json([
                'status' => true,
                'message' => 'Extension officer found',
                'data' => $officerPayload,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error searching extension officer: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to search extension officer',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new extension officer farm invite
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $authenticatedUser = $request->user();

            // Verify user is a farmer
            if ($authenticatedUser->role !== UserRole::FARMER) {
                return response()->json([
                    'status' => false,
                    'message' => 'Only farmers can invite extension officers',
                ], 403);
            }

            $farmerId = $authenticatedUser->roleId;

            $validator = Validator::make($request->all(), [
                'extensionOfficerEmail' => 'required|email',
                'access_code' => [
                    'required',
                    'string',
                    'unique:extension_officer_farm_invites,access_code',
                    'regex:/^ACODE-\d{5}[A-Z]{3}=7-\d{2}$/',
                ],
            ], [
                'access_code.regex' => 'The access code format is invalid. Expected format: ACODE-{5numbers}{3letters}=7-{2numbers}',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $email = $request->input('extensionOfficerEmail');

            // Find extension officer by email
            $extensionOfficer = ExtensionOfficer::where('email', $email)->first();

            if (! $extensionOfficer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Extension officer not found with this email',
                ], 404);
            }

            // Check if invite already exists
            $existingInvite = ExtensionOfficerFarmInvite::where('extensionOfficerId', $extensionOfficer->id)
                ->where('farmerId', $farmerId)
                ->first();

            if ($existingInvite) {
                return response()->json([
                    'status' => false,
                    'message' => 'Extension officer has already been invited to your farm',
                    'data' => [
                        'id' => $existingInvite->id,
                        'inviteId' => $existingInvite->id,
                        'access_code' => $existingInvite->access_code,
                        'extensionOfficerId' => $existingInvite->extensionOfficerId,
                        'farmerId' => $existingInvite->farmerId,
                        'created_at' => $existingInvite->created_at ? $existingInvite->created_at->toDateTimeString() : null,
                        'updated_at' => $existingInvite->updated_at ? $existingInvite->updated_at->toDateTimeString() : null,
                    ],
                ], 409);
            }

            // Get access code from request (generated by frontend)
            $accessCode = $request->input('access_code');

            // Create new invite with access code from frontend
            $invite = ExtensionOfficerFarmInvite::create([
                'extensionOfficerId' => $extensionOfficer->id,
                'farmerId' => $farmerId,
                'access_code' => $accessCode,
            ]);

            Log::info("✅ Extension officer farm invite created: Officer ID {$extensionOfficer->id}, Farmer ID {$farmerId}, Access Code: {$invite->access_code}");

            // Get farmer (owner) details
            $farmer = Farmer::find($farmerId);
            if ($farmer) {
                // Get first farm for context (if available)
                $farm = Farm::where('farmerId', $farmerId)->first();
                $farmName = $farm ? ($farm->name ?? null) : null;

                // Send SMS invitation
                $this->sendExtensionOfficerInvitationSms($extensionOfficer, $farmer, $accessCode, $farmName);

                // Send email invitation
                $this->sendExtensionOfficerInvitationEmail($extensionOfficer, $farmer, $accessCode, $farmName);
            }

            // Return invite and officer details
            $invitePayload = [
                'id' => $invite->id,
                'inviteId' => $invite->id,
                'access_code' => $invite->access_code,
                'extensionOfficerId' => $extensionOfficer->id,
                'farmerId' => $farmerId,
                'created_at' => $invite->created_at ? $invite->created_at->toDateTimeString() : null,
                'updated_at' => $invite->updated_at ? $invite->updated_at->toDateTimeString() : null,
            ];

            $officerPayload = [
                'id' => $extensionOfficer->id,
                'firstName' => $extensionOfficer->firstName,
                'middleName' => $extensionOfficer->middleName,
                'lastName' => $extensionOfficer->lastName,
                'email' => $extensionOfficer->email,
                'phone' => $extensionOfficer->phone,
                'specialization' => $extensionOfficer->specialization,
                'organization' => $extensionOfficer->organization,
                'countryId' => $extensionOfficer->countryId,
                'regionId' => $extensionOfficer->regionId,
                'districtId' => $extensionOfficer->districtId,
                'wardId' => $extensionOfficer->wardId,
                'isVerified' => $extensionOfficer->isVerified == 1 ? true : false,
                'created_at' => $extensionOfficer->created_at ? $extensionOfficer->created_at->toDateTimeString() : null,
                'updated_at' => $extensionOfficer->updated_at ? $extensionOfficer->updated_at->toDateTimeString() : null,
            ];

            return response()->json([
                'status' => true,
                'message' => 'Extension officer invited successfully',
                'data' => array_merge($invitePayload, ['extensionOfficer' => $officerPayload]),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating extension officer farm invite: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to create extension officer invite',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send SMS invitation to extension officer
     */
    private function sendExtensionOfficerInvitationSms(
        ExtensionOfficer $extensionOfficer,
        Farmer $farmer,
        string $accessCode,
        ?string $farmName = null
    ): void {
        try {
            $farmerName = trim(($farmer->firstName ?? '').' '.($farmer->middleName ?? '').' '.($farmer->surname ?? ''));
            $farmerName = $farmerName ?: 'Farm Owner';

            $extensionOfficerName = trim(($extensionOfficer->firstName ?? '').' '.($extensionOfficer->middleName ?? '').' '.($extensionOfficer->lastName ?? ''));
            $extensionOfficerName = $extensionOfficerName ?: 'Extension Officer';

            // Build SMS message
            $message = "Hello {$extensionOfficerName},\n\n";
            $message .= 'You have been invited to join ';
            if ($farmName) {
                $message .= "the farm: {$farmName}";
            } else {
                $message .= 'a farm';
            }
            $message .= " by {$farmerName}.\n\n";
            $message .= "Access Code: {$accessCode}\n\n";
            $message .= "Login Credentials:\n";
            $message .= "Email: {$extensionOfficer->email}\n";
            $message .= "Password: password\n\n";
            $message .= "Farm Owner Contact:\n";
            if ($farmer->phone1) {
                $message .= "Phone: {$farmer->phone1}\n";
            }
            if ($farmer->email) {
                $message .= "Email: {$farmer->email}\n";
            }
            $message .= "\nThank you!";

            // Send SMS to extension officer's phone
            $phoneNumber = $extensionOfficer->phone;
            if (empty($phoneNumber)) {
                Log::warning("⚠️ No phone number found for extension officer: {$extensionOfficer->email}");

                return;
            }

            $result = $this->smsService->sendSms($message, $phoneNumber);

            if (is_string($result)) {
                Log::warning("⚠️ Failed to send SMS to {$phoneNumber}: {$result}");
            } else {
                Log::info("✅ SMS invitation sent successfully to: {$phoneNumber}");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error sending SMS invitation to extension officer {$extensionOfficer->email}: ".$e->getMessage());
        }
    }

    /**
     * Send email invitation to extension officer
     */
    private function sendExtensionOfficerInvitationEmail(
        ExtensionOfficer $extensionOfficer,
        Farmer $farmer,
        string $accessCode,
        ?string $farmName = null
    ): void {
        try {
            Log::info("📧 Sending extension officer invitation email to: {$extensionOfficer->email}");

            Mail::to($extensionOfficer->email)->send(
                new ExtensionOfficerInvitationMail($extensionOfficer, $farmer, $accessCode, $farmName)
            );

            Log::info("✅ Email invitation sent successfully to: {$extensionOfficer->email}");
        } catch (\Exception $e) {
            Log::error("❌ Error sending email invitation to extension officer {$extensionOfficer->email}: ".$e->getMessage());
            Log::error('❌ Stack trace: '.$e->getTraceAsString());
        }
    }

    /**
     * Fetch invited extension officers for sync (Farmer Data)
     */
    public function fetchByFarmerId(int $farmerId): array
    {
        return ExtensionOfficerFarmInvite::with('extensionOfficer')
            ->where('farmerId', $farmerId)
            ->get()
            ->map(function ($invite) {
                $officer = $invite->extensionOfficer;
                if (! $officer) {
                    return null;
                }

                // Ensure we're getting fresh officer data
                $freshOfficer = \App\Models\ExtensionOfficer::find($officer->id);
                
                if (! $freshOfficer) {
                    return null;
                }

                return [
                    'id' => $invite->id,
                    'inviteId' => $invite->id,
                    'access_code' => $invite->access_code,
                    'officerId' => $freshOfficer->id,
                    'firstName' => $freshOfficer->firstName,
                    'middleName' => $freshOfficer->middleName,
                    'lastName' => $freshOfficer->lastName,
                    'email' => $freshOfficer->email,
                    'phone' => $freshOfficer->phone,
                    'specialization' => $freshOfficer->specialization,
                    'organization' => $freshOfficer->organization,
                    'countryId' => $freshOfficer->countryId,
                    'regionId' => $freshOfficer->regionId,
                    'districtId' => $freshOfficer->districtId,
                    'wardId' => $freshOfficer->wardId,
                    'isVerified' => (bool) $freshOfficer->isVerified, // Direct from extension_officers table
                    'createdAt' => $invite->created_at ? $invite->created_at->toIso8601String() : null,
                    'updatedAt' => $invite->updated_at ? $invite->updated_at->toIso8601String() : null,
                    'officerUpdatedAt' => $freshOfficer->updated_at ? $freshOfficer->updated_at->toIso8601String() : null,
                ];
            })
            ->filter(function ($item) {
                return $item !== null && isset($item['officerId']) && $item['officerId'] !== null;
            })
            ->values()
            ->toArray();
    }

    /**
     * Sync endpoint: return all invited extension officers for a farmer.
     * Accepts 'farmerId' in request body or uses authenticated farmer's roleId.
     * Returns JSON payload similar to invite response so the frontend can upsert locally.
     */
    public function syncByFarmer(Request $request): JsonResponse
    {
        try {
            $authenticatedUser = $request->user();

            $farmerId = $request->input('farmerId');

            if (empty($farmerId)) {
                if ($authenticatedUser && $authenticatedUser->role === UserRole::FARMER) {
                    $farmerId = $authenticatedUser->roleId;
                } else {
                    return response()->json([
                        'status' => false,
                        'message' => 'farmerId is required',
                        'data' => null,
                    ], 422);
                }
            }

            $items = $this->fetchByFarmerId((int) $farmerId);

            return response()->json([
                'status' => true,
                'message' => 'Invited extension officers fetched',
                'data' => $items,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error syncing invited extension officers: '.$e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch invited extension officers',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process sync for invited extension officers (Handle deletions)
     *
     * @return array Synced IDs (deleted IDs)
     */
    public function processSync(array $invites, int $farmerId): array
    {
        $syncedIds = [];

        foreach ($invites as $item) {
            try {
                // Determine the sync action (accept both snake_case and camelCase)
                $action = $item['syncAction'] ?? $item['sync_action'] ?? null;

                // Handle deletions first
                if ($action === 'deleted') {
                    $inviteId = $item['inviteId'] ?? $item['id'] ?? null;
                    if ($inviteId) {
                        // Verify ownership and delete
                        $deleted = ExtensionOfficerFarmInvite::where('id', $inviteId)
                            ->where('farmerId', $farmerId)
                            ->delete();

                        // Return the invite id so client can mark as synced/removed
                        $syncedIds[] = $inviteId;
                    }

                    continue;
                }

                // For non-deletion items, attempt to upsert the extension officer and invite
                $officerPayload = $item['extensionOfficer'] ?? ($item['extension_officer'] ?? null);

                $email = null;
                if (is_array($officerPayload) && isset($officerPayload['email'])) {
                    $email = $officerPayload['email'];
                }
                // Fallback to top-level email fields
                if (! $email) {
                    $email = $item['email'] ?? $item['extensionOfficerEmail'] ?? $item['extension_officer_email'] ?? null;
                }

                $extensionOfficer = null;
                if ($email) {
                    $extensionOfficer = ExtensionOfficer::where('email', $email)->first();
                }

                // If payload present, upsert officer details
                if (is_array($officerPayload)) {
                    $officerData = [
                        'firstName' => $officerPayload['firstName'] ?? $officerPayload['first_name'] ?? null,
                        'middleName' => $officerPayload['middleName'] ?? $officerPayload['middle_name'] ?? null,
                        'lastName' => $officerPayload['lastName'] ?? $officerPayload['last_name'] ?? null,
                        'email' => $officerPayload['email'] ?? $officerPayload['email'] ?? null,
                        'phone' => $officerPayload['phone'] ?? $officerPayload['phone'] ?? null,
                        'specialization' => $officerPayload['specialization'] ?? $officerPayload['specialization'] ?? null,
                        'organization' => $officerPayload['organization'] ?? $officerPayload['organization'] ?? null,
                        'countryId' => $officerPayload['countryId'] ?? $officerPayload['country_id'] ?? null,
                        'regionId' => $officerPayload['regionId'] ?? $officerPayload['region_id'] ?? null,
                        'districtId' => $officerPayload['districtId'] ?? $officerPayload['district_id'] ?? null,
                        'wardId' => $officerPayload['wardId'] ?? $officerPayload['ward_id'] ?? null,
                        'isVerified' => isset($officerPayload['isVerified']) ? (bool) $officerPayload['isVerified'] : (isset($officerPayload['is_verified']) ? (bool) $officerPayload['is_verified'] : null),
                    ];

                    if ($extensionOfficer) {
                        // Update existing officer with incoming data (only non-null fields)
                        $extensionOfficer->fill(array_filter($officerData, function ($v) {
                            return $v !== null;
                        }));
                        $extensionOfficer->save();
                    } else {
                        // Create new officer record if email provided
                        if (! empty($officerData['email'])) {
                            $extensionOfficer = ExtensionOfficer::create(array_filter($officerData, function ($v) {
                                return $v !== null;
                            }));
                        }
                    }
                }

                // Determine extensionOfficerId for invite upsert
                $extensionOfficerId = $extensionOfficer ? $extensionOfficer->id : ($item['extensionOfficerId'] ?? $item['officerId'] ?? $item['extension_officer_id'] ?? null);

                // Determine access code and inviteId
                $accessCode = $item['access_code'] ?? $item['accessCode'] ?? null;
                $inviteId = $item['inviteId'] ?? $item['id'] ?? null;

                // Find existing invite by priority: inviteId, access_code+farmer, extensionOfficerId+farmer
                $invite = null;
                if ($inviteId) {
                    $invite = ExtensionOfficerFarmInvite::where('id', $inviteId)->where('farmerId', $farmerId)->first();
                }
                if (! $invite && $accessCode) {
                    $invite = ExtensionOfficerFarmInvite::where('access_code', $accessCode)->where('farmerId', $farmerId)->first();
                }
                if (! $invite && $extensionOfficerId) {
                    $invite = ExtensionOfficerFarmInvite::where('extensionOfficerId', $extensionOfficerId)->where('farmerId', $farmerId)->first();
                }

                if ($invite) {
                    // Update invite fields if necessary
                    $dirty = false;
                    if ($extensionOfficerId && $invite->extensionOfficerId !== $extensionOfficerId) {
                        $invite->extensionOfficerId = $extensionOfficerId;
                        $dirty = true;
                    }
                    if ($accessCode && $invite->access_code !== $accessCode) {
                        $invite->access_code = $accessCode;
                        $dirty = true;
                    }
                    if ($dirty) {
                        $invite->save();
                    }
                } else {
                    // Create new invite
                    $invite = ExtensionOfficerFarmInvite::create([
                        'extensionOfficerId' => $extensionOfficerId,
                        'farmerId' => $farmerId,
                        'access_code' => $accessCode ?? '',
                    ]);
                }

                // Acknowledge synced invite id for client to mark as synced
                if ($invite && $invite->id) {
                    $syncedIds[] = $invite->id;
                }
            } catch (\Exception $e) {
                Log::error('Error syncing invite item: '.$e->getMessage());
            }
        }

        return $syncedIds;
    }
}
