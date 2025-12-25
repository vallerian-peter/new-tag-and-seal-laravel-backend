<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\FarmUserInvitationMail;
use App\Models\User;
use App\Models\Farmer;
use App\Models\SystemUser;
use App\Models\FarmUser;
use App\Models\ExtensionOfficer;
use App\Models\ExtensionOfficerFarmInvite;
use App\Models\OtpVerification;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Services\SmsService;
use App\Services\BrevoEmailService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use App\Traits\ConvertsDateFormat;
use Carbon\Carbon;

class AuthController extends Controller
{
    use ConvertsDateFormat;

    private SmsService $smsService;
    private BrevoEmailService $brevoEmailService;

    public function __construct()
    {
        $this->smsService = new SmsService();
        $this->brevoEmailService = new BrevoEmailService();
    }

    /**
     * Register a new user.
     * Supports multiple roles: farmer, system_user, farm_invited_user, extension_officer, vet
     */
    public function register(Request $request): JsonResponse
    {
        // Convert dateOfBirth before validation if present
        $requestData = $request->all();
        if (isset($requestData['dateOfBirth']) && !empty($requestData['dateOfBirth'])) {
            $convertedDate = $this->convertDateFormat($requestData['dateOfBirth']);
            if ($convertedDate !== null) {
                $requestData['dateOfBirth'] = $convertedDate;
                // Also update the request object so createProfileRecord gets the converted date
                $request->merge(['dateOfBirth' => $convertedDate]);
            }
        }

        $validator = Validator::make($requestData, [
            'username'   => 'required|string|unique:users,username',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'nullable|string|min:8',
            'role'       => ['required', 'string', Rule::in(UserRole::all())],
            'createdBy'  => 'nullable|integer',
            'updatedBy'  => 'nullable|integer',

            // Farmer-specific fields
            'firstName'  => 'required_if:role,farmer,systemUser,extensionOfficer,vet,farmInvitedUser|string|max:255',
            'middleName' => 'nullable|string|max:255',
            'surname'    => 'required_if:role,farmer|string|max:255',
            'phone1'     => 'required_if:role,farmer|string',
            'phone2'     => 'nullable|string',
            'physicalAddress'             => 'nullable|string',
            'farmerOrganizationMembership'=> 'nullable|string',
            'dateOfBirth'                => 'nullable|date',
            'gender'                     => 'required_if:role,farmer,farmInvitedUser|in:male,female',
            'identityCardTypeId'         => 'nullable|integer',
            'identityNumber'             => 'nullable|string',
            'streetId'                   => 'nullable|integer',
            'schoolLevelId'              => 'nullable|integer',
            'villageId'                  => 'nullable|integer',
            'wardId'                     => 'nullable|integer',
            'districtId'                 => 'nullable|integer',
            'regionId'                   => 'nullable|integer',
            'countryId'                  => 'nullable|integer',
            'farmerType'                 => 'nullable|in:individual,organization',

            // SystemUser / Extension Officer / Vet / Farm Invited User
            'lastName'  => 'required_if:role,systemUser,extensionOfficer,vet,farmInvitedUser|string|max:255',
            'phone'     => 'required_if:role,systemUser,extensionOfficer,vet,farmInvitedUser|string',
            'address'   => 'nullable|string',

            // FarmUser-specific fields (for farmInvitedUser)
            'farmUuid'  => 'required_if:role,farmInvitedUser|string',
            'roleTitle' => [
                'required_if:role,farmInvitedUser',
                Rule::in([
                    'farm-manager',
                    'feeding-user',
                    'weight-change-user',
                    'deworming-user',
                    'medication-user',
                    'vaccination-user',
                    'disposal-user',
                    'birth-event-user',
                    'aborted-pregnancy-user',
                    'dryoff-user',
                    'insemination-user',
                    'pregnancy-user',
                    'milking-user',
                    'transfer-user',
                ]),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Merge converted dateOfBirth back into request for createProfileRecord
            if (isset($requestData['dateOfBirth'])) {
                $request->merge(['dateOfBirth' => $requestData['dateOfBirth']]);
            }

            // Create profile record first to get role_id
            $profileRecord = $this->createProfileRecord($request);

            if (!$profileRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to create profile record'
                ], 500);
            }

            // Determine plain password (email fallback) for login + email
            $plainPassword = $request->password ?? $request->email;

            // Determine username with fallback logic: username ?? lastname ?? email prefix ?? email
            $username = $request->username ?? '';

            if (empty($username) && $request->role === UserRole::FARM_INVITED_USER && $profileRecord instanceof FarmUser) {
                // Prefer lastname for farm invited users
                if (!empty($profileRecord->lastName)) {
                    $username = $profileRecord->lastName;
                }
            }

            if (empty($username) && !empty($request->email) && str_contains($request->email, '@')) {
                // Use email prefix (before @)
                $username = strstr($request->email, '@', true);
            }

            if (empty($username)) {
                // Fallback to full email or generated username
                $username = $request->email ?? 'user_' . $profileRecord->id;
            }

            // Create user account
            $user = User::create([
                'username' => $username,
                'email' => $request->email,
                'password' => Hash::make($plainPassword),
                'role' => $request->role,
                'roleId' => $profileRecord->id,
                'status' => UserStatus::ACTIVE,
                'createdBy' => $request->createdBy ?? null,
                'updatedBy' => $request->updatedBy ?? null,
            ]);

            // If this is a farm invited user, send invitation SMS with credentials
            if ($request->role === UserRole::FARM_INVITED_USER && $profileRecord instanceof FarmUser) {
                $this->sendFarmUserInvitationSms($profileRecord, $user->email, $plainPassword);
            }

            // If this is a farmer, send welcome SMS with credentials
            if ($request->role === UserRole::FARMER && $profileRecord instanceof Farmer) {
                $this->sendFarmerWelcomeSms($profileRecord, $user->email, $plainPassword);
            }

            // Generate API token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'role' => $user->role,
                        'roleId' => $user->roleId,
                        'status' => $user->status,
                        'firstname' => $profileRecord->firstName ?? '',
                        'surname' => $profileRecord->surname ?? '',
                        'phone1' => $profileRecord->phone1 ?? '',
                        'physicalAddress' => $profileRecord->physicalAddress ?? '',
                        'dateOfBirth' => $profileRecord->dateOfBirth ?? '',
                        'gender' => $profileRecord->gender ?? '',
                    ],
                    'profile' => $profileRecord,
                    'accessToken' => $token,
                    'tokenType' => 'Bearer',
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Registration failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login user with username/email and password.
     *
     * Supports both:
     * - New structure (documented):  login, password, device_name
     * - Legacy/mobile structure:     username, password, deviceName
     */
    public function login(Request $request): JsonResponse
    {
        // Support both `login` and `username` as the identifier
        // and both `device_name` and `deviceName` for device label.
        $loginValue = $request->input('login', $request->input('username'));
        $deviceName = $request->input('device_name', $request->input('deviceName'));

        $validator = Validator::make(
            [
                'login' => $loginValue,
                'password' => $request->input('password'),
                'device_name' => $deviceName,
            ],
            [
                'login' => 'required|string',
                'password' => 'required|string',
                'device_name' => 'nullable|string',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Determine if login is email or username
        $fieldType = filter_var($loginValue, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        // Find user by email or username
        $user = User::where($fieldType, $loginValue)->first();

        // Enhanced logging for debugging farm user login
        if ($fieldType === 'email') {
            Log::info("🔐 Login attempt with email: {$loginValue}");
            if (!$user) {
                Log::warning("❌ No user found with email: {$loginValue}");
            } else {
                Log::info("✅ User found: ID={$user->id}, Email={$user->email}, Username={$user->username}, Role={$user->role}");
            }
        } else {
            Log::info("🔐 Login attempt with username: {$loginValue}");
            if (!$user) {
                Log::warning("❌ No user found with username: {$loginValue}");
            } else {
                Log::info("✅ User found: ID={$user->id}, Email={$user->email}, Username={$user->username}, Role={$user->role}");
            }
        }

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'The provided credentials are incorrect.'
            ], 401);
        }

        // Check password - log details for debugging
        $passwordMatches = Hash::check($request->password, $user->password);
        Log::info("🔐 Password check for user {$user->email}: " . ($passwordMatches ? '✅ MATCH' : '❌ NO MATCH'));

        if (!$passwordMatches) {
            // Additional logging for farm users
            if ($user->role === UserRole::FARM_INVITED_USER) {
                Log::warning("❌ Farm user login failed - Password mismatch for: {$user->email}");
                Log::info("💡 Expected password (for reference): User's email address");
                Log::info("💡 Provided password: " . (strlen($request->password) > 0 ? '***' : 'EMPTY'));
            }

            return response()->json([
                'status' => false,
                'message' => 'The provided credentials are incorrect.'
            ], 401);
        }

        // Check if user account is active
        if (!$user->isActive()) {
            return response()->json([
                'status' => false,
                'message' => 'Your account has been deactivated. Please contact support.'
            ], 403);
        }

        // Get profile data
        $profileData = $this->getProfileData($user);

        if (!$profileData) {
            Log::error("❌ Profile data not found for user ID: {$user->id}, Role: {$user->role}");
            return response()->json([
                'status' => false,
                'message' => 'User profile not found. Please contact support.'
            ], 404);
        }

        // Check profile status for all roles (all profile models now have status field)
        if ($user->role === UserRole::FARMER && $profileData instanceof Farmer) {
            if ($profileData->status !== 'active') {
                Log::warning("❌ Farmer profile is not active for user ID: {$user->id}, Farmer ID: {$profileData->id}, Status: {$profileData->status}");
                return response()->json([
                    'status' => false,
                    'message' => 'Your farmer account has been deactivated. Please contact support.'
                ], 403);
            }
        } elseif ($user->role === UserRole::FARM_INVITED_USER && $profileData instanceof FarmUser) {
            if ($profileData->status !== 'active') {
                Log::warning("❌ FarmUser profile is not active for user ID: {$user->id}, FarmUser ID: {$profileData->id}, Status: {$profileData->status}");
                return response()->json([
                    'status' => false,
                    'message' => 'Your farm user account has been deactivated. Please contact support.'
                ], 403);
            }
        } elseif (in_array($user->role, [UserRole::SYSTEM_USER, UserRole::EXTENSION_OFFICER, UserRole::VET]) && $profileData instanceof SystemUser) {
            if ($profileData->status !== 'active') {
                Log::warning("❌ SystemUser profile is not active for user ID: {$user->id}, SystemUser ID: {$profileData->id}, Status: {$profileData->status}");
                return response()->json([
                    'status' => false,
                    'message' => 'Your account has been deactivated. Please contact support.'
                ], 403);
            }
        }

        // Handle different field names for different user roles
        // Farmer uses: surname, phone1
        // FarmUser uses: lastName, phone
        // SystemUser uses: lastName, phone
        $surname = null;
        $phone1 = null;

        if ($user->role === UserRole::FARMER && $profileData instanceof Farmer) {
            $surname = $profileData->surname ?? '';
            $phone1 = $profileData->phone1 ?? '';
        } elseif ($user->role === UserRole::FARM_INVITED_USER && $profileData instanceof FarmUser) {
            // FarmUser uses lastName instead of surname
            $surname = $profileData->lastName ?? '';
            // FarmUser uses phone instead of phone1
            $phone1 = $profileData->phone ?? '';
        } elseif (in_array($user->role, [UserRole::SYSTEM_USER, UserRole::EXTENSION_OFFICER, UserRole::VET]) && $profileData instanceof SystemUser) {
            // SystemUser uses lastName instead of surname
            $surname = $profileData->lastName ?? '';
            // SystemUser uses phone instead of phone1
            $phone1 = $profileData->phone ?? '';
        }

        // Generate API token
        $resolvedDeviceName = $deviceName ?? $request->header('User-Agent') ?? 'unknown';
        $token = $user->createToken($resolvedDeviceName)->plainTextToken;

        Log::info("✅ Login successful for user: {$user->email}, Role: {$user->role}");

        return response()->json([
            'status' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'roleId' => $user->roleId,
                    'firstname' => $profileData->firstName ?? '',
                    'surname' => $surname ?? '',
                    'phone1' => $phone1 ?? '',
                    'physicalAddress' => $profileData->physicalAddress ?? $profileData->address ?? '',
                    'dateOfBirth' => $profileData->dateOfBirth ?? '',
                    'gender' => $profileData->gender ?? ''
                ],
                'profile' => $profileData,
                // New/documented field names
                'access_token' => $token,
                'token_type' => 'Bearer',
                // Backwards-compatible field names
                'accessToken' => $token,
                'tokenType' => 'Bearer',
            ]
        ], 200);
    }

    /**
     * Extension Officer login using three fields: email, access_code, password.
     * Validates invite existence and officer password, then issues Sanctum token
     * via a standard User record (role=extensionOfficer, roleId=extension_officers.id).
     */
    public function extensionOfficerLogin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'access_code' => 'required|string',
            'password' => 'required|string',
            'deviceName' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $email = $request->input('email');
            $accessCode = $request->input('access_code');
            $plainPassword = $request->input('password');

            // 1) First, find the invite by email and access_code
            // Join with extension_officers table to validate email + access_code combination
            $invite = ExtensionOfficerFarmInvite::whereHas('extensionOfficer', function ($query) use ($email) {
                $query->where('email', $email);
            })
            ->where('access_code', $accessCode)
            ->with('extensionOfficer')
            ->first();

            if (! $invite) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid email or access code. Please check your credentials.',
                ], 401);
            }

            // 2) Get the Extension Officer from the invite
            $officer = $invite->extensionOfficer;

            if (! $officer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Extension officer not found',
                ], 404);
            }

            // 3) Validate password using Hash::check() (support both hashed and legacy plaintext)
            $passwordMatches = false;
            $stored = (string) ($officer->password ?? '');

            if (!empty($stored)) {
                // First check if the stored password is already hashed
                if (preg_match('/^\$2[ayb]\$.{56}$/', $stored)) {
                    // Password is already hashed, use Hash::check
                    $passwordMatches = Hash::check($plainPassword, $stored);
                } else {
                    // Password is plaintext, compare directly
                    $passwordMatches = hash_equals($stored, $plainPassword);

                    // If password matches, update to hashed version
                    if ($passwordMatches) {
                        $officer->password = Hash::make($plainPassword);
                        $officer->save();
                        Log::info("🔑 Migrated extension officer #{$officer->id} password to hashed");
                    }
                }
            }

            if (! $passwordMatches) {
                return response()->json([
                    'status' => false,
                    'message' => 'The email or password is not correct'
                ], 401);
            }

            // 4) Find or create User record for Sanctum token issuance
            $user = User::where('role', UserRole::EXTENSION_OFFICER)
                ->where('roleId', $officer->id)
                ->first();

            if (! $user) {
                // Auto-create User account for Extension Officer
                $username = strstr($email, '@', true) ?: (string) Str::uuid();
                $baseUsername = $username;
                $i = 1;
                while (User::where('username', $username)->exists()) {
                    $username = $baseUsername . $i;
                    $i++;
                    if ($i > 1000) {
                        $username = (string) Str::uuid();
                        break;
                    }
                }

                $user = User::create([
                    'username' => $username,
                    'email' => $email,
                    'password' => Hash::make($plainPassword),
                    'role' => UserRole::EXTENSION_OFFICER,
                    'roleId' => $officer->id,
                    'status' => UserStatus::ACTIVE,
                ]);

                Log::info("✅ Auto-created User account for extension officer: {$email}, User ID: {$user->id}");
            }

            // Verify user account is active
            if ($user->status !== UserStatus::ACTIVE) {
                Log::warning("❌ Extension officer user account is not active: {$email}, User ID: {$user->id}");
                return response()->json([
                    'status' => false,
                    'message' => 'Your account has been deactivated. Please contact support.',
                ], 403);
            }

            // 5) Issue Sanctum token
            $deviceName = $request->deviceName ?? $request->header('User-Agent') ?? 'unknown';
            $token = $user->createToken($deviceName)->plainTextToken;

            // 6) Build response payloads
            $userPayload = [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'roleId' => $user->roleId,
                'firstname' => $officer->firstName ?? '',
                'surname' => $officer->lastName ?? '',
                'phone1' => $officer->phone ?? '',
                'physicalAddress' => $officer->address ?? '',
                'dateOfBirth' => '',
                'gender' => $officer->gender ?? '',
            ];

            $profilePayload = [
                'id' => $officer->id,
                'firstName' => $officer->firstName,
                'middleName' => $officer->middleName,
                'lastName' => $officer->lastName,
                'email' => $officer->email,
                'phone' => $officer->phone,
                'gender' => $officer->gender,
                'licenseNumber' => $officer->licenseNumber,
                'address' => $officer->address,
                'countryId' => $officer->countryId,
                'regionId' => $officer->regionId,
                'districtId' => $officer->districtId,
                'wardId' => $officer->wardId,
                'organization' => $officer->organization,
                'isVerified' => (bool) $officer->isVerified,
                'specialization' => $officer->specialization,
                'created_at' => optional($officer->created_at)->toDateTimeString(),
                'updated_at' => optional($officer->updated_at)->toDateTimeString(),
            ];

            $invitePayload = [
                'id' => $invite->id,
                'inviteId' => $invite->id,
                'access_code' => $invite->access_code,
                'extensionOfficerId' => $invite->extensionOfficerId,
                'farmerId' => $invite->farmerId,
                'created_at' => optional($invite->created_at)->toDateTimeString(),
                'updated_at' => optional($invite->updated_at)->toDateTimeString(),
            ];

            Log::info("✅ Extension officer login successful: {$email}, OfficerID={$officer->id}");

            return response()->json([
                'status' => true,
                'message' => 'Login successful',
                'data' => [
                    'user' => $userPayload,
                    'profile' => $profilePayload,
                    'invite' => $invitePayload,
                    'accessToken' => $token,
                    'tokenType' => 'Bearer',
                ],
            ], 200);
        } catch (\Exception $e) {
            Log::error('❌ Extension officer login error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Login failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Logout user (revoke current token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Logged out successfully'
        ], 200);
    }

    /**
     * Logout from all devices (revoke all tokens).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Logged out from all devices successfully'
        ], 200);
    }

    /**
     * Get authenticated user profile.
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $profileData = $this->getProfileData($user);

        return response()->json([
            'status' => true,
            'message' => 'Profile retrieved successfully',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'roleId' => $user->roleId,
                    'status' => $user->status,
                ],
                'profile' => $profileData,
            ]
        ], 200);
    }

    /**
     * Update user password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        if (!Hash::check($request->currentPassword, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Current password is incorrect'
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->newPassword),
            'updatedBy' => $user->id,
        ]);

        // Send SMS notification about password change
        $profileData = $this->getProfileData($user);
        if ($profileData) {
            $this->sendPasswordChangeNotificationSms($user, $profileData);
        }

        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully'
        ], 200);
    }

    /**
     * Send OTP for password reset via SMS.
     * Accepts either email or phone.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:phone|email|nullable',
            'phone' => 'required_without:email|string|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $email = $request->email;
            $phone = $request->phone;

            // Find user by email or phone across all role tables
            $userProfile = null;
            $userRole = null;
            $contactMethod = null;

            if ($email) {
                // Check Farmer
                $farmer = Farmer::where('email', $email)->first();
                if ($farmer) {
                    $userProfile = $farmer;
                    $userRole = UserRole::FARMER;
                    $contactMethod = $farmer->phone1;
                }

                // Check FarmUser
                if (!$userProfile) {
                    $farmUser = FarmUser::where('email', $email)->first();
                    if ($farmUser) {
                        $userProfile = $farmUser;
                        $userRole = UserRole::FARM_INVITED_USER;
                        $contactMethod = $farmUser->phone;
                    }
                }

                // Check ExtensionOfficer
                if (!$userProfile) {
                    $extensionOfficer = ExtensionOfficer::where('email', $email)->first();
                    if ($extensionOfficer) {
                        $userProfile = $extensionOfficer;
                        $userRole = UserRole::EXTENSION_OFFICER;
                        $contactMethod = $extensionOfficer->phone;
                    }
                }
            } elseif ($phone) {
                // Check Farmer by phone
                $farmer = Farmer::where('phone1', $phone)->orWhere('phone2', $phone)->first();
                if ($farmer) {
                    $userProfile = $farmer;
                    $userRole = UserRole::FARMER;
                    $contactMethod = $phone;
                }

                // Check FarmUser by phone
                if (!$userProfile) {
                    $farmUser = FarmUser::where('phone', $phone)->first();
                    if ($farmUser) {
                        $userProfile = $farmUser;
                        $userRole = UserRole::FARM_INVITED_USER;
                        $contactMethod = $phone;
                    }
                }

                // Check ExtensionOfficer by phone
                if (!$userProfile) {
                    $extensionOfficer = ExtensionOfficer::where('phone', $phone)->first();
                    if ($extensionOfficer) {
                        $userProfile = $extensionOfficer;
                        $userRole = UserRole::EXTENSION_OFFICER;
                        $contactMethod = $phone;
                    }
                }
            }

            if (!$userProfile) {
                return response()->json([
                    'status' => false,
                    'message' => 'No account found with the provided email or phone number.'
                ], 404);
            }

            // Generate OTP
            $otp = OtpVerification::generateOtp();
            $expiresAt = Carbon::now()->addMinutes(10);

            // Update existing OTP or create new one
            if ($email) {
                OtpVerification::updateOrCreate(
                    ['email' => $email],
                    [
                        'otp' => $otp,
                        'expires_at' => $expiresAt,
                        'verified' => false,
                        'phone' => null,
                    ]
                );
            } elseif ($phone) {
                OtpVerification::updateOrCreate(
                    ['phone' => $phone],
                    [
                        'otp' => $otp,
                        'expires_at' => $expiresAt,
                        'verified' => false,
                        'email' => null,
                    ]
                );
            }

            // Send OTP via Email or SMS
            $userName = $userProfile->firstName ?? 'User';

            if ($email) {
                // Send OTP via Email using Brevo
                $subject = 'Password Reset OTP - Tag & Seal';
                $htmlContent = "
                    <html>
                    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                        <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                            <h2 style='color: #2c3e50;'>Password Reset Request</h2>
                            <p>Hello <strong>{$userName}</strong>,</p>
                            <p>You have requested to reset your password. Please use the following OTP code:</p>
                            <div style='background-color: #f8f9fa; border-left: 4px solid #007bff; padding: 15px; margin: 20px 0;'>
                                <h1 style='margin: 0; color: #007bff; font-size: 32px; letter-spacing: 5px;'>{$otp}</h1>
                            </div>
                            <p><strong>Important:</strong></p>
                            <ul>
                                <li>This code will expire in <strong>10 minutes</strong></li>
                                <li>Do not share this code with anyone</li>
                                <li>If you didn't request this, please ignore this email</li>
                            </ul>
                            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                            <p style='color: #666; font-size: 12px;'>This is an automated message from Tag & Seal Livestock Management System.</p>
                        </div>
                    </body>
                    </html>
                ";
                $textContent = "Hello {$userName}, your password reset OTP is: {$otp}. This code will expire in 10 minutes. Do not share this code with anyone.";

                $emailSent = $this->brevoEmailService->sendTransactionalEmail(
                    $email,
                    $userName,
                    $subject,
                    $htmlContent,
                    $textContent
                );

                if (!$emailSent) {
                    Log::warning("Failed to send OTP email to {$email}");
                }
            } elseif ($phone && $contactMethod) {
                // Send OTP via SMS
                $message = "Hello {$userName}, your password reset OTP is: {$otp}. This code will expire in 10 minutes. Do not share this code with anyone.";

                $smsSent = $this->smsService->sendSms($message, [$contactMethod]);

                if (!$smsSent) {
                    Log::warning("Failed to send OTP SMS to {$contactMethod}");
                } else {
                    Log::info("OTP SMS sent successfully to {$contactMethod}");
                }
            }

            Log::info("OTP sent successfully for " . ($email ?? $phone));

            return response()->json([
                'status' => true,
                'message' => 'OTP sent successfully to your registered phone number.',
                'data' => [
                    'expiresAt' => $expiresAt->toDateTimeString(),
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Send OTP error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to send OTP: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify OTP and reset password.
     * Accepts email/phone, OTP, and new password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required_without:phone|email|nullable',
            'phone' => 'required_without:email|string|nullable',
            'otp' => 'required|string|size:6',
            'newPassword' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $email = $request->email;
            $phone = $request->phone;
            $otp = $request->otp;
            $newPassword = $request->newPassword;

            // Find OTP verification record
            $otpRecord = null;
            if ($email) {
                $otpRecord = OtpVerification::where('email', $email)
                    ->where('otp', $otp)
                    ->where('verified', false)
                    ->first();
            } elseif ($phone) {
                $otpRecord = OtpVerification::where('phone', $phone)
                    ->where('otp', $otp)
                    ->where('verified', false)
                    ->first();
            }

            if (!$otpRecord) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid OTP or OTP has already been used.'
                ], 401);
            }

            // Check if OTP is expired
            if ($otpRecord->isExpired()) {
                return response()->json([
                    'status' => false,
                    'message' => 'OTP has expired. Please request a new one.'
                ], 401);
            }

            // Find user profile and update password
            $userProfile = null;
            $userRole = null;

            if ($email) {
                // Check Farmer
                $farmer = Farmer::where('email', $email)->first();
                if ($farmer) {
                    $userProfile = $farmer;
                    $userRole = UserRole::FARMER;
                }

                // Check FarmUser
                if (!$userProfile) {
                    $farmUser = FarmUser::where('email', $email)->first();
                    if ($farmUser) {
                        $userProfile = $farmUser;
                        $userRole = UserRole::FARM_INVITED_USER;
                    }
                }

                // Check ExtensionOfficer
                if (!$userProfile) {
                    $extensionOfficer = ExtensionOfficer::where('email', $email)->first();
                    if ($extensionOfficer) {
                        $userProfile = $extensionOfficer;
                        $userRole = UserRole::EXTENSION_OFFICER;
                    }
                }
            } elseif ($phone) {
                // Check Farmer by phone
                $farmer = Farmer::where('phone1', $phone)->orWhere('phone2', $phone)->first();
                if ($farmer) {
                    $userProfile = $farmer;
                    $userRole = UserRole::FARMER;
                }

                // Check FarmUser by phone
                if (!$userProfile) {
                    $farmUser = FarmUser::where('phone', $phone)->first();
                    if ($farmUser) {
                        $userProfile = $farmUser;
                        $userRole = UserRole::FARM_INVITED_USER;
                    }
                }

                // Check ExtensionOfficer by phone
                if (!$userProfile) {
                    $extensionOfficer = ExtensionOfficer::where('phone', $phone)->first();
                    if ($extensionOfficer) {
                        $userProfile = $extensionOfficer;
                        $userRole = UserRole::EXTENSION_OFFICER;
                    }
                }
            }

            if (!$userProfile) {
                return response()->json([
                    'status' => false,
                    'message' => 'User account not found.'
                ], 404);
            }

            // Update password in profile table (for ExtensionOfficer)
            if ($userRole === UserRole::EXTENSION_OFFICER && $userProfile instanceof ExtensionOfficer) {
                $userProfile->update([
                    'password' => Hash::make($newPassword)
                ]);
            }

            // Update password in users table
            $user = User::where('role', $userRole)
                ->where('roleId', $userProfile->id)
                ->first();

            if ($user) {
                $user->update([
                    'password' => Hash::make($newPassword)
                ]);
            }

            // Mark OTP as verified
            $otpRecord->markAsVerified();

            Log::info("Password reset successfully for " . ($email ?? $phone));

            return response()->json([
                'status' => true,
                'message' => 'Password reset successfully. You can now login with your new password.'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Reset password error: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to reset password: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user profile (Farmer details).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $profileData = $this->getProfileData($user);

        if (!$profileData) {
            return response()->json([
                'status' => false,
                'message' => 'User profile not found. Please contact support.'
            ], 404);
        }

        // Only allow farmers to update their profile for now
        if ($user->role !== UserRole::FARMER || !($profileData instanceof Farmer)) {
            return response()->json([
                'status' => false,
                'message' => 'Profile update is only available for farmers.'
            ], 403);
        }

        // Convert dateOfBirth before validation if present
        $requestData = $request->all();
        if (isset($requestData['dateOfBirth']) && !empty($requestData['dateOfBirth'])) {
            $convertedDate = $this->convertDateFormat($requestData['dateOfBirth']);
            if ($convertedDate !== null) {
                $requestData['dateOfBirth'] = $convertedDate;
                // Also update the request object so it gets the converted date
                $request->merge(['dateOfBirth' => $convertedDate]);
            }
        }

        // Validation rules for farmer profile update
        $validator = Validator::make($requestData, [
            'firstName'  => 'nullable|string|max:255',
            'middleName' => 'nullable|string|max:255',
            'surname'    => 'nullable|string|max:255',
            'phone1'     => 'nullable|string',
            'phone2'     => 'nullable|string',
            'email'      => 'nullable|email|unique:users,email,' . $user->id,
            'physicalAddress' => 'nullable|string',
            'farmerOrganizationMembership' => 'nullable|string',
            'dateOfBirth' => 'nullable|date',
            'gender' => 'nullable|in:male,female',
            'identityCardTypeId' => 'nullable|integer|exists:identity_card_types,id',
            'identityNumber' => 'nullable|string',
            'streetId' => 'nullable|integer|exists:streets,id',
            'schoolLevelId' => 'nullable|integer|exists:school_levels,id',
            'villageId' => 'nullable|integer|exists:villages,id',
            'wardId' => 'nullable|integer|exists:wards,id',
            'districtId' => 'nullable|integer|exists:districts,id',
            'regionId' => 'nullable|integer|exists:regions,id',
            'countryId' => 'nullable|integer|exists:countries,id',
            'farmerType' => 'nullable|in:individual,organization',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Prepare update data for farmer profile
            $farmerUpdateData = [];
            $allowedFields = [
                'firstName', 'middleName', 'surname', 'phone1', 'phone2', 'email',
                'physicalAddress', 'farmerOrganizationMembership', 'dateOfBirth',
                'gender', 'identityCardTypeId', 'identityNumber', 'streetId',
                'schoolLevelId', 'villageId', 'wardId', 'districtId', 'regionId',
                'countryId', 'farmerType'
            ];

            foreach ($allowedFields as $field) {
                if ($request->has($field) && $request->$field !== null) {
                    // Convert date format for dateOfBirth
                    if ($field === 'dateOfBirth') {
                        $farmerUpdateData[$field] = $this->convertDateFormat($request->$field);
                    } else {
                        $farmerUpdateData[$field] = $request->$field;
                    }
                }
            }

            // Update farmer profile
            $profileData->update($farmerUpdateData);

            // Update user table if email changed
            $userUpdateData = [];
            if ($request->has('email') && $request->email !== null && $request->email !== $user->email) {
                $userUpdateData['email'] = $request->email;
                $userUpdateData['updatedBy'] = $user->id;
            }

            if (!empty($userUpdateData)) {
                $user->update($userUpdateData);
            }

            // Refresh profile data to get updated values
            $profileData->refresh();
            $user->refresh();

            Log::info("✅ Profile updated successfully for farmer: {$user->email}");

            return response()->json([
                'status' => true,
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'username' => $user->username,
                        'email' => $user->email,
                        'role' => $user->role,
                        'roleId' => $user->roleId,
                        'firstname' => $profileData->firstName ?? '',
                        'surname' => $profileData->surname ?? '',
                        'phone1' => $profileData->phone1 ?? '',
                        'physicalAddress' => $profileData->physicalAddress ?? '',
                        'dateOfBirth' => $profileData->dateOfBirth ?? '',
                        'gender' => $profileData->gender ?? '',
                    ],
                    'profile' => $profileData,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error("❌ Error updating profile for user {$user->email}: " . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Failed to update profile: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create profile record based on role.
     */
    private function createProfileRecord(Request $request)
    {
        switch ($request->role) {
            case UserRole::FARMER:
                return Farmer::create([
                    'farmerNo' => $this->generateFarmerNumber(),
                    'firstName' => $request->firstName,
                    'middleName' => $request->middleName,
                    'surname' => $request->surname,
                    'phone1' => $request->phone1,
                    'phone2' => $request->phone2,
                    'email' => $request->email,
                    'physicalAddress' => $request->physicalAddress,
                    'farmerOrganizationMembership' => $request->farmerOrganizationMembership,
                    'dateOfBirth' => $this->convertDateFormat($request->dateOfBirth),
                    'gender' => $request->gender,
                    'identityCardTypeId' => $request->identityCardTypeId,
                    'identityNumber' => $request->identityNumber,
                    'streetId' => $request->streetId,
                    'schoolLevelId' => $request->schoolLevelId,
                    'villageId' => $request->villageId,
                    'wardId' => $request->wardId,
                    'districtId' => $request->districtId,
                    'regionId' => $request->regionId,
                    'countryId' => $request->countryId ?? 1,
                    'farmerType' => $request->farmerType ?? 'individual',
                    'createdBy' => $request->createdBy ?? null,
                    'status' => 'active',
                ]);

            case UserRole::SYSTEM_USER:
            case UserRole::EXTENSION_OFFICER:
            case UserRole::VET:
                return SystemUser::create([
                    'firstName' => $request->firstName,
                    'middleName' => $request->middleName,
                    'lastName' => $request->lastName,
                    'phone' => $request->phone,
                    'address' => $request->address,
                    'status' => 'active',
                    'createdBy' => $request->createdBy ?? null,
                ]);

            case UserRole::FARM_INVITED_USER:
                return FarmUser::create([
                    'uuid'       => $request->uuid ?? (string) Str::uuid(),
                    'farmUuid'   => $request->farmUuid,
                    'firstName'  => $request->firstName,
                    'middleName' => $request->middleName,
                    'lastName'   => $request->lastName,
                    'phone'      => $request->phone,
                    'email'      => $request->email,
                    'roleTitle'  => $request->roleTitle,
                    'gender'     => $request->gender,
                    'status'     => 'active',
                ]);

            default:
                return null;
        }
    }

    /**
     * Get profile data based on role.
     */
    private function getProfileData(User $user)
    {
        switch ($user->role) {
            case UserRole::FARMER:
                return Farmer::find($user->roleId);

            case UserRole::SYSTEM_USER:
            case UserRole::EXTENSION_OFFICER:
            case UserRole::VET:
                return SystemUser::find($user->roleId);

            case UserRole::FARM_INVITED_USER:
                return FarmUser::find($user->roleId);

            default:
                return null;
        }
    }

    /**
     * Generate unique farmer number.
     */
    private function generateFarmerNumber(): string
    {
        $prefix = 'FMR';
        $year = date('Y');
        $lastFarmer = Farmer::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastFarmer ? intval(substr($lastFarmer->farmerNo, -6)) + 1 : 1;

        return $prefix . $year . str_pad($sequence, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Send SMS invitation to farm user
     *
     * @param FarmUser $farmUser
     * @param string $email
     * @param string $password
     * @return void
     */
    private function sendFarmUserInvitationSms(FarmUser $farmUser, string $email, string $password): void
    {
        try {
            // Get farm details (use first farm if multiple)
            $farmUuids = $farmUser->getFarmUuidsArray();
            if (empty($farmUuids)) {
                Log::warning("⚠️ No farm UUIDs found for farm user: {$farmUser->email}");
                return;
            }

            $firstFarmUuid = $farmUuids[0];
            $farm = \App\Models\Farm::where('uuid', $firstFarmUuid)->first();

            if (!$farm) {
                Log::warning("⚠️ Farm not found for UUID: {$firstFarmUuid}");
                return;
            }

            $farmName = $farm->name ?? 'Unknown Farm';
            $farmOwnerDetails = $this->getFarmOwnerDetails($firstFarmUuid);

            // Build SMS message
            $message = $this->smsService->buildFarmUserWelcomeMessage(
                $email,
                $password,
                $farmName,
                $farmUser->roleTitle,
                $farmOwnerDetails['phone'],
                $farmOwnerDetails['email']
            );

            // Send SMS to farm user's phone
            $phoneNumber = $farmUser->phone;
            if (empty($phoneNumber)) {
                Log::warning("⚠️ No phone number found for farm user: {$farmUser->email}");
                return;
            }

            $result = $this->smsService->sendSms($message, $phoneNumber);

            if (is_string($result)) {
                // Error occurred
                Log::warning("⚠️ Failed to send SMS to {$phoneNumber}: {$result}");
            } else {
                Log::info("✅ SMS invitation sent successfully to: {$phoneNumber}");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error sending SMS invitation to farm user {$farmUser->email}: " . $e->getMessage());
        }
    }

    /**
     * Send welcome SMS to farmer
     *
     * @param Farmer $farmer
     * @param string $email
     * @param string $password
     * @return void
     */
    private function sendFarmerWelcomeSms(Farmer $farmer, string $email, string $password): void
    {
        try {
            $farmerName = trim(($farmer->firstName ?? '') . ' ' . ($farmer->middleName ?? '') . ' ' . ($farmer->surname ?? ''));
            $farmerName = $farmerName ?: 'Farmer';

            // Build SMS message
            $message = $this->smsService->buildFarmerWelcomeMessage(
                $email,
                $password,
                $farmerName
            );

            // Send SMS to farmer's phone
            $phoneNumber = $farmer->phone1 ?? $farmer->phone2 ?? null;
            if (empty($phoneNumber)) {
                Log::warning("⚠️ No phone number found for farmer: {$farmer->email}");
                return;
            }

            $result = $this->smsService->sendSms($message, $phoneNumber);

            if (is_string($result)) {
                // Error occurred
                Log::warning("⚠️ Failed to send SMS to {$phoneNumber}: {$result}");
            } else {
                Log::info("✅ Welcome SMS sent successfully to farmer: {$phoneNumber}");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error sending welcome SMS to farmer {$farmer->email}: " . $e->getMessage());
        }
    }

    /**
     * Get farm owner contact details (phone and email) from farm UUID
     *
     * @param string $farmUuid
     * @return array{phone: string|null, email: string|null}
     */
    private function getFarmOwnerDetails(string $farmUuid): array
    {
        try {
            $farm = \App\Models\Farm::where('uuid', $farmUuid)->with('farmer')->first();

            if (!$farm || !$farm->farmer) {
                Log::warning("⚠️ Farm or farmer not found for UUID: {$farmUuid}");
                return ['phone' => null, 'email' => null];
            }

            $farmer = $farm->farmer;
            return [
                'phone' => $farmer->phone1 ?? $farmer->phone2 ?? null,
                'email' => $farmer->email ?? null,
            ];
        } catch (\Exception $e) {
            Log::error("❌ Error getting farm owner details for UUID {$farmUuid}: " . $e->getMessage());
            return ['phone' => null, 'email' => null];
        }
    }

    /**
     * Send SMS notification when password is changed
     *
     * @param User $user
     * @param mixed $profileData
     * @return void
     */
    private function sendPasswordChangeNotificationSms(User $user, $profileData): void
    {
        try {
            $phoneNumber = null;
            $userName = '';

            // Get phone number and user name based on role
            if ($user->role === UserRole::FARMER && $profileData instanceof Farmer) {
                $phoneNumber = $profileData->phone1 ?? $profileData->phone2 ?? null;
                $userName = trim(($profileData->firstName ?? '') . ' ' . ($profileData->surname ?? ''));
            } elseif ($user->role === UserRole::FARM_INVITED_USER && $profileData instanceof FarmUser) {
                $phoneNumber = $profileData->phone ?? null;
                $userName = trim(($profileData->firstName ?? '') . ' ' . ($profileData->lastName ?? ''));
            } elseif (in_array($user->role, [UserRole::SYSTEM_USER, UserRole::EXTENSION_OFFICER, UserRole::VET]) && $profileData instanceof SystemUser) {
                $phoneNumber = $profileData->phone ?? null;
                $userName = trim(($profileData->firstName ?? '') . ' ' . ($profileData->lastName ?? ''));
            }

            if (empty($phoneNumber)) {
                Log::warning("⚠️ No phone number found for user: {$user->email}");
                return;
            }

            $userName = $userName ?: 'User';

            // Build SMS message
            $message = "Hello {$userName}, your password has been successfully changed. If you didn't make this change, please contact support immediately. - Tag & Seal";

            Log::info("📱 Sending password change notification SMS to: {$phoneNumber}");

            // Send SMS
            $result = $this->smsService->sendSms($message, $phoneNumber);

            if (is_string($result)) {
                // Error occurred
                Log::warning("⚠️ Failed to send password change notification SMS to {$phoneNumber}: {$result}");
            } else {
                Log::info("✅ Password change notification SMS sent successfully to: {$phoneNumber}");
            }
        } catch (\Exception $e) {
            Log::error("❌ Error sending password change notification SMS for user {$user->email}: " . $e->getMessage());
        }
    }
}
