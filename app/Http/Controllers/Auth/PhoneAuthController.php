<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\User;
use App\Services\Auth\LoginMembershipSnapshot;
use App\Services\Hiring\OnboardingService;
use App\Services\PhoneAuthentication;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PhoneAuthController extends Controller
{
    public function __construct(private readonly PhoneAuthentication $phones) {}

    private function phone(Request $request): ?string
    {
        $request->validate(['phone' => ['required', 'string', 'max:32']]);

        return $this->phones->normalize($request->string('phone')->toString());
    }

    /** Register needs no PIN yet; reset needs an existing account (an older account may not have a PIN yet). */
    private function purposeFits(?string $purpose, ?object $identity, array $ids = []): bool
    {
        return match ($purpose) {
            'register' => ! $this->hasPin($identity),
            'reset' => (bool) $identity || count($ids) === 1,
            default => false,
        };
    }

    private function hasPin(?object $identity): bool
    {
        return $identity && ! $identity->pin_pending && filled($identity->pin_hash);
    }

    /** Name already on record for the account behind this phone, so its owner only chooses a PIN. */
    private function knownName(string $phone, ?object $identity, array $ids): ?string
    {
        if (filled($identity?->display_name)) {
            return trim($identity->display_name);
        }
        $user = count($ids) === 1 ? User::find($ids[0]) : null;
        if (! $user) {
            return null;
        }
        $profile = $this->profile($user);
        $name = trim(implode(' ', array_filter([
            $profile->firstName ?? null,
            $profile->middleName ?? null,
            $profile->surname ?? $profile->lastName ?? null,
        ], fn ($part) => filled($part))));

        return $name !== '' ? $name : (filled($user->name) ? trim($user->name) : null);
    }

    private function requiresReview(string $phone, array $ids): bool
    {
        if (count($ids) > 1) {
            return true;
        }

        return count($ids) === 1 && DB::table('phone_identities')
            ->where('user_id', $ids[0])->where('phone', '!=', $phone)->exists();
    }

    public function check(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        if (! $phone) {
            return response()->json([
                'code' => 'INVALID_PHONE',
                'message' => 'The phone number format is invalid.',
            ], 422);
        }
        $ids = $this->phones->matchingUserIds($phone);
        if ($this->requiresReview($phone, $ids)) {
            return response()->json([
                'code' => 'PHONE_REVIEW_REQUIRED',
                'message' => 'This phone number is linked to multiple accounts. Please contact support.',
            ], 409);
        }
        $identity = DB::table('phone_identities')->where('phone', $phone)->first();

        // 'setup': an account already exists for this phone (made by AMCOS, a transporter, or an older
        // system record) but its owner has not chosen a PIN yet.
        $next = $this->hasPin($identity) ? 'pin' : ($identity || count($ids) === 1 ? 'setup' : 'otp');

        return response()->json(['next' => $next]);
    }

    public function sendOtp(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        if (! $phone) {
            return response()->json([
                'code' => 'INVALID_PHONE',
                'message' => 'The phone number format is invalid.',
            ], 422);
        }
        $request->validate(['purpose' => ['required', 'in:register,reset']]);
        $ids = $this->phones->matchingUserIds($phone);
        if ($this->requiresReview($phone, $ids)) {
            return response()->json([
                'code' => 'PHONE_REVIEW_REQUIRED',
                'message' => 'This phone number is linked to multiple accounts. Please contact support.',
            ], 409);
        }
        $identity = DB::table('phone_identities')->where('phone', $phone)->first();
        if (! $this->purposeFits($request->input('purpose'), $identity, $ids)) {
            return response()->json([
                'code' => 'INVALID_AUTH_FLOW',
                'message' => 'Invalid authentication flow for this phone account state.',
            ], 422);
        }
        $rateKey = 'phone-otp:'.hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            return response()->json([
                'code' => 'OTP_RATE_LIMITED',
                'message' => 'Too many OTP requests. Please wait a few minutes before trying again.',
            ], 429);
        }
        $existing = DB::table('phone_auth_challenges')->where('phone', $phone)->first();
        if ($existing && Carbon::parse($existing->updated_at)->addSeconds(60)->isFuture()) {
            return response()->json([
                'code' => 'OTP_RESEND_WAIT',
                'message' => 'Please wait before requesting a new code.',
            ], 429);
        }
        RateLimiter::hit($rateKey, 900);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Log::info("📱 [OTP DISPATCH] Code for {$phone} is: {$code}");
        DB::table('phone_auth_challenges')->updateOrInsert(['phone' => $phone], [
            'purpose' => $request->input('purpose'), 'code_hash' => $this->phones->hashCode($phone, $code),
            'proof_hash' => null, 'attempts' => 0, 'verified_at' => null,
            'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sent = $this->phones->sendCode($phone, $code);
        if (! $sent) {
            DB::table('phone_auth_challenges')->where('phone', $phone)->delete();
            Log::error("📱 [OTP RESPONSE] send-otp FAILED for {$phone}: OTP_DELIVERY_FAILED (503)");

            return response()->json([
                'code' => 'OTP_DELIVERY_FAILED',
                'message' => 'Unable to send SMS code right now. Please try again later.',
            ], 503);
        }

        $payload = ['expiresIn' => 300, 'resendAfter' => 60];
        if (config('app.debug')) {
            // Full diagnostics for development only; never exposed in production.
            $payload['debug'] = [
                'phone' => $phone,
                'purpose' => $request->input('purpose'),
                'gatewayResponse' => $this->phones->lastGatewayResponse,
                'expiresAt' => now()->addMinutes(5)->toDateTimeString(),
            ];
        }
        Log::info("📱 [OTP RESPONSE] send-otp OK for {$phone}: ".json_encode($payload));

        return response()->json($payload);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $request->validate(['otp' => ['required', 'regex:/^[0-9]{6}$/']]);
        if (! $phone) {
            return response()->json([
                'code' => 'INVALID_PHONE',
                'message' => 'The phone number format is invalid.',
            ], 422);
        }

        return DB::transaction(function () use ($phone, $request): JsonResponse {
            $query = DB::table('phone_auth_challenges')->where('phone', $phone);
            $challenge = (clone $query)->lockForUpdate()->first();
            if (! $challenge || $challenge->verified_at || $challenge->attempts >= 5 || now()->greaterThanOrEqualTo(Carbon::parse($challenge->expires_at))) {
                return response()->json([
                    'code' => 'OTP_INVALID_OR_EXPIRED',
                    'message' => 'The code is invalid or has expired. Please try again.',
                ], 422);
            }
            if (! hash_equals($challenge->code_hash, $this->phones->hashCode($phone, $request->input('otp')))) {
                $query->increment('attempts');

                return response()->json([
                    'code' => 'OTP_INVALID_OR_EXPIRED',
                    'message' => 'The code is invalid or has expired. Please try again.',
                ], 422);
            }
            $proof = bin2hex(random_bytes(32));
            $query->update([
                'proof_hash' => hash('sha256', $proof),
                'verified_at' => now(),
                'expires_at' => now()->addMinutes(15),
                'updated_at' => now(),
            ]);

            $identity = DB::table('phone_identities')->where('phone', $phone)->first();
            $ids = $this->phones->matchingUserIds($phone);

            return response()->json([
                'verificationToken' => $proof,
                'displayName' => $this->hasPin($identity) || count($ids) > 1 ? null : $this->knownName($phone, $identity, $ids),
            ]);
        });
    }

    public function login(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $request->validate(['pin' => ['required', 'regex:/^[0-9]{4}$/']]);
        if (! $phone) {
            return response()->json([
                'code' => 'INVALID_PHONE',
                'message' => 'The phone number format is invalid.',
            ], 422);
        }
        $key = 'phone-pin:'.hash('sha256', $phone);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'code' => 'PIN_RATE_LIMITED',
                'message' => 'Too many PIN attempts. Please wait a few minutes before trying again.',
            ], 429);
        }
        $identity = DB::table('phone_identities')->where('phone', $phone)->first();
        $user = $identity ? User::find($identity->user_id) : null;
        if (! $identity || ! Hash::check($this->phones->pinMaterial($request->input('pin')), $identity->pin_hash) || ! $user || ! $user->isActive()) {
            RateLimiter::hit($key, 900);

            return response()->json([
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Invalid PIN entered.',
            ], 401);
        }
        RateLimiter::clear($key);

        return $this->session($user, $phone, $identity->display_name);
    }

    public function complete(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $request->validate([
            'verificationToken' => ['required', 'string', 'size:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'pin' => ['required', 'regex:/^[0-9]{4}$/', 'confirmed'],
        ]);
        if (! $phone) {
            return response()->json([
                'code' => 'INVALID_PHONE',
                'message' => 'The phone number format is invalid.',
            ], 422);
        }

        return DB::transaction(function () use ($phone, $request): JsonResponse {
            $query = DB::table('phone_auth_challenges')->where('phone', $phone);
            $challenge = (clone $query)->lockForUpdate()->first();
            if (! $challenge || ! $challenge->verified_at || ! $challenge->proof_hash || now()->greaterThanOrEqualTo(Carbon::parse($challenge->expires_at)) || ! hash_equals($challenge->proof_hash, hash('sha256', $request->input('verificationToken')))) {
                return response()->json([
                    'code' => 'VERIFICATION_EXPIRED',
                    'message' => 'Verification expired. Please request a new code.',
                ], 422);
            }
            $ids = $this->phones->matchingUserIds($phone);
            if ($this->requiresReview($phone, $ids)) {
                return response()->json([
                    'code' => 'PHONE_REVIEW_REQUIRED',
                    'message' => 'This phone number is linked to multiple accounts. Please contact support.',
                ], 409);
            }
            $identity = DB::table('phone_identities')->where('phone', $phone)->first();
            if (! $this->purposeFits($challenge->purpose, $identity, $ids)) {
                return response()->json([
                    'code' => 'INVALID_AUTH_FLOW',
                    'message' => 'Invalid authentication flow for this phone account state.',
                ], 422);
            }
            $user = count($ids) ? User::find($ids[0]) : null;
            if (! $user && $challenge->purpose === 'register') {
                if (! trim((string) $request->input('name'))) {
                    return response()->json([
                        'code' => 'NAME_REQUIRED',
                        'message' => 'Your name is required to complete registration.',
                    ], 422);
                }
                $id = (string) Str::uuid();
                $user = new User;
                $user->forceFill(['username' => 'phone-'.$id, 'email' => $id.'@accounts.invalid', 'password' => Str::random(64), 'role' => 'appUser', 'roleId' => 0, 'status' => 'active']);
                $user->save();
            }
            if (! $user || ! $user->isActive() || ! $this->profileAvailable($user)) {
                return response()->json([
                    'code' => 'ACCOUNT_UNAVAILABLE',
                    'message' => 'Account is unavailable. Please contact support.',
                ], 403);
            }
            // A name already on record is kept; the owner cannot rename the account while setting a PIN.
            $name = $this->knownName($phone, $identity, $ids) ?? (trim((string) $request->input('name')) ?: null);
            DB::table('phone_identities')->updateOrInsert(['user_id' => $user->id], [
                'phone' => $phone, 'display_name' => $name, 'pin_hash' => Hash::make($this->phones->pinMaterial($request->input('pin'))), 'pin_pending' => false, 'created_at' => $identity?->created_at ?? now(), 'updated_at' => now(),
            ]);
            if ($challenge->purpose === 'reset') {
                $user->tokens()->delete();
            }
            $query->delete();

            return $this->session($user, $phone, $name);
        });
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->isActive() || ! $this->profileAvailable($user)) {
            return response()->json(['code' => 'ACCOUNT_UNAVAILABLE'], 403);
        }
        $identity = DB::table('phone_identities')->where('user_id', $user->id)->first();
        $phone = $identity?->phone ?? '';
        $name = $identity?->display_name;

        $oldToken = $user->currentAccessToken();
        $response = $this->session($user, $phone, $name);
        if ($response->getStatusCode() === 200) {
            $oldToken?->delete();
        }

        return $response;
    }

    private function session(User $user, string $phone, ?string $name = null): JsonResponse
    {
        $profile = $this->profile($user);
        if (! $this->profileAvailable($user, $profile)) {
            return response()->json(['code' => 'ACCOUNT_UNAVAILABLE'], 403);
        }
        app(OnboardingService::class)->claim($user);
        $token = $user->createToken('phone')->plainTextToken;

        $phoneIdentity = DB::table('phone_identities')->where('user_id', $user->id)->first();
        $realName = $name ?: ($phoneIdentity?->display_name ?? $user->name);
        $realPhone = $phone ?: ($phoneIdentity?->phone ?? $user->phone ?? '');
        // A phone-only account stores one full name; split it once so first + middle + surname rebuild it exactly.
        $parts = preg_split('/\s+/', trim((string) $realName), -1, PREG_SPLIT_NO_EMPTY);
        $fn = $profile->firstName ?? ($parts[0] ?? '');
        $mn = $profile->middleName ?? (count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : '');
        $sn = $profile->surname ?? $profile->lastName ?? (count($parts) > 1 ? end($parts) : '');

        $farmerId = null;
        if ($user->role === 'farmer' && $user->roleId) {
            $farmerId = $user->roleId;
        }
        if (! $farmerId) {
            $fmFarmer = DB::table('feature_memberships')->where('user_id', $user->id)->whereNotNull('farmer_id')->first();
            if ($fmFarmer) {
                $farmerId = $fmFarmer->farmer_id;
            }
        }
        if (! $farmerId && ! empty($realPhone)) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $realPhone);
            $suffix = substr($cleanPhone, -9);
            $f = Farmer::where('phone1', 'like', "%{$suffix}%")->orWhere('phone2', 'like', "%{$suffix}%")->first();
            if ($f) {
                $farmerId = $f->id;
            }
        }

        $farmer = $farmerId ? Farmer::find($farmerId) : null;
        $farms = $farmerId ? Farm::where('farmerId', $farmerId)->get() : collect();
        $primaryFarm = $farms->first();
        $memberships = app(LoginMembershipSnapshot::class)->forUser($user);

        $fmRecords = DB::table('feature_memberships')->where('user_id', $user->id)->get();
        $fmDetails = [];
        foreach ($fmRecords as $fm) {
            if (! empty($fm->details)) {
                $decoded = json_decode($fm->details, true);
                if (is_array($decoded)) {
                    $fmDetails = array_merge($fmDetails, $decoded);
                }
            }
        }

        $physicalAddress = $profile->physicalAddress ?? $farmer?->physicalAddress ?? $primaryFarm?->physicalAddress ?? null;
        if (empty($physicalAddress) && ! empty($fmDetails)) {
            $locParts = array_filter([
                $fmDetails['villageOrStreet'] ?? null,
                $fmDetails['ward'] ?? null,
                $fmDetails['district'] ?? null,
                $fmDetails['region'] ?? null,
            ]);
            if (! empty($locParts)) {
                $physicalAddress = implode(', ', $locParts);
            }
        }
        $physicalAddress ??= ($user->physicalAddress ?? '');

        $dateOfBirth = $profile->dateOfBirth ?? $farmer?->dateOfBirth ?? ($fmDetails['dateOfBirth'] ?? '');
        $gender = $profile->gender ?? $farmer?->gender ?? ($fmDetails['gender'] ?? 'male');
        $farmName = $primaryFarm?->name ?? '';
        $farmAddress = $primaryFarm?->physicalAddress ?? '';
        $businessName = $farmName ?: ($fmDetails['businessName'] ?? $realName);
        $tradingCategory = $fmDetails['tradingCategory'] ?? ($primaryFarm ? 'Commercial Livestock Rancher & Trader' : 'Livestock Trader');

        if (! $profile) {
            $profile = (object) [
                'id' => $user->id,
                'firstName' => $fn,
                'middleName' => $mn,
                'surname' => $sn,
                'lastName' => $sn,
                'name' => $realName,
                'phone1' => $realPhone,
                'phone' => $realPhone,
                'email' => str_ends_with($user->email ?? '', '@accounts.invalid') ? '' : ($user->email ?? ''),
                'role' => $user->role,
                'roleTitle' => $primaryFarm ? 'Rancher / Seller' : 'Buyer / Trader',
                'physicalAddress' => $physicalAddress,
                'location' => $physicalAddress,
                'farm_name' => $farmName,
                'farm_address' => $farmAddress,
                'dateOfBirth' => $dateOfBirth,
                'gender' => $gender,
                'businessName' => $businessName,
                'tradingCategory' => $tradingCategory,
                'accountTier' => 'Level 2 Verified (KYC & NIDA)',
                'farmerNo' => $farmer?->farmerNo ?? '',
            ];
        } else {
            $profile->farm_name = $farmName;
            $profile->farm_address = $farmAddress;
            $profile->physicalAddress = $physicalAddress;
            $profile->location = $physicalAddress;
            $profile->businessName = $businessName;
            $profile->tradingCategory = $tradingCategory;
            if ($farmer) {
                $profile->farmerNo = $farmer->farmerNo;
            }
        }

        $coopMembership = DB::table('cooperative_user_memberships as um')
            ->join('cooperative_amcos as a', 'a.uuid', '=', 'um.amcos_uuid')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'a.mcu_uuid')
            ->where('um.user_id', $user->id)
            ->where('um.status', 'approved')
            ->select(
                'um.role as coop_role',
                'a.id as amcos_id',
                'a.uuid as amcos_uuid',
                'a.name as amcos_name',
                'm.id as mcu_id',
                'm.uuid as mcu_uuid',
                'm.name as mcu_name'
            )
            ->first();

        if (! $coopMembership) {
            $farmerCoop = DB::table('cooperative_farmer_memberships as fm')
                ->join('cooperative_amcos as a', 'a.uuid', '=', 'fm.amcos_uuid')
                ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'a.mcu_uuid')
                ->where('fm.farmer_user_id', $user->id)
                ->where('fm.status', 'active')
                ->select(
                    'a.id as amcos_id',
                    'a.uuid as amcos_uuid',
                    'a.name as amcos_name',
                    'm.id as mcu_id',
                    'm.uuid as mcu_uuid',
                    'm.name as mcu_name'
                )
                ->first();
            if ($farmerCoop) {
                $coopMembership = (object) array_merge((array) $farmerCoop, ['coop_role' => 'farmer']);
            }
        }

        $amcosId = $coopMembership?->amcos_id;
        $amcosUuid = $coopMembership?->amcos_uuid;
        $amcosName = $coopMembership?->amcos_name;
        $mcuId = $coopMembership?->mcu_id;
        $mcuUuid = $coopMembership?->mcu_uuid;
        $mcuName = $coopMembership?->mcu_name;
        $coopRole = $coopMembership?->coop_role;

        $profile->amcosId = $amcosId;
        $profile->amcosUuid = $amcosUuid;
        $profile->amcosName = $amcosName;
        $profile->mcuId = $mcuId;
        $profile->mcuUuid = $mcuUuid;
        $profile->mcuName = $mcuName;
        $profile->cooperativeRole = $coopRole;

        return response()->json(['data' => [
            'user' => [
                'id' => $user->id,
                'username' => str_starts_with($user->username, 'phone-') ? ($realPhone ?: $user->username) : $user->username,
                'name' => $realName,
                'email' => str_ends_with($user->email ?? '', '@accounts.invalid') ? '' : ($user->email ?? ''),
                'role' => $user->role,
                'platformRole' => $user->platform_role,
                'roleId' => $user->roleId,
                'firstname' => $fn,
                'middlename' => $mn,
                'surname' => $sn,
                'phone1' => $realPhone,
                'physicalAddress' => $physicalAddress,
                'farm_name' => $farmName,
                'farm_address' => $farmAddress,
                'dateOfBirth' => $dateOfBirth,
                'gender' => $gender,
                'amcosId' => $amcosId,
                'amcosUuid' => $amcosUuid,
                'amcosName' => $amcosName,
                'mcuId' => $mcuId,
                'mcuUuid' => $mcuUuid,
                'mcuName' => $mcuName,
                'cooperativeRole' => $coopRole,
            ],
            'profile' => $profile,
            'memberships' => $memberships,
            'accessToken' => $token,
            'tokenType' => 'Bearer',
        ]]);
    }

    private function profile(User $user): ?object
    {
        return match ($user->role) {
            'farmer' => DB::table('farmers')->where('id', $user->roleId)->first(),
            'farmInvitedUser' => DB::table('farm_users')->where('id', $user->roleId)->first(),
            'systemUser', 'vet' => DB::table('system_users')->where('id', $user->roleId)->first(),
            'extensionOfficer' => DB::table('extension_officers')->where('id', $user->roleId)->where('email', $user->email)->select('id', 'firstName', 'middleName', 'lastName', 'phone', 'address')->first()
                ?? DB::table('system_users')->where('id', $user->roleId)->first(),
            default => null,
        };
    }

    private function profileAvailable(User $user, ?object $profile = null): bool
    {
        if ($user->role === 'appUser') {
            return true;
        }
        $profile ??= $this->profile($user);

        return $profile !== null && (! isset($profile->status) || $profile->status === 'active');
    }
}
