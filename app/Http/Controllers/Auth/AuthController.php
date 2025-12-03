<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\FarmUserInvitationMail;
use App\Models\User;
use App\Models\Farmer;
use App\Models\SystemUser;
use App\Models\FarmUser;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Register a new user.
     * Supports multiple roles: farmer, system_user, farm_invited_user, extension_officer, vet
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
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

            // If this is a farm invited user, send invitation email with credentials
            if ($request->role === UserRole::FARM_INVITED_USER && $profileRecord instanceof FarmUser) {
                try {
                    Mail::to($user->email)->send(
                        new FarmUserInvitationMail($profileRecord, $user->email, $plainPassword)
                    );
                } catch (\Throwable $mailException) {
                    // Do not fail registration if email sending fails
                    // You may log this if needed.
                }
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
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
            'deviceName' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Determine if username is email or username
        $fieldType = filter_var($request->username, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        // Find user by email or username
        $user = User::where($fieldType, $request->username)->first();

        // Enhanced logging for debugging farm user login
        if ($fieldType === 'email') {
            Log::info("🔐 Login attempt with email: {$request->username}");
            if (!$user) {
                Log::warning("❌ No user found with email: {$request->username}");
            } else {
                Log::info("✅ User found: ID={$user->id}, Email={$user->email}, Username={$user->username}, Role={$user->role}");
            }
        } else {
            Log::info("🔐 Login attempt with username: {$request->username}");
            if (!$user) {
                Log::warning("❌ No user found with username: {$request->username}");
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

        // Check if user is active
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
        $deviceName = $request->deviceName ?? $request->header('User-Agent') ?? 'unknown';
        $token = $user->createToken($deviceName)->plainTextToken;

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
                'accessToken' => $token,
                'tokenType' => 'Bearer',
            ]
        ], 200);
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
            'newPassword' => 'required|string|min:8|confirmed',
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

        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully'
        ], 200);
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
                    'dateOfBirth' => $request->dateOfBirth,
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
}