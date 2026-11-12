<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemUser;
use App\Models\ExtensionOfficer;
use App\Models\User;
use App\Services\Auth\AdminAccountDeletion;
use App\Services\PhoneAuthentication;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->details($request->user())]);
    }

    public function update(Request $request, PhoneAuthentication $phones): JsonResponse
    {
        $user = $request->user();
        foreach (['firstName', 'lastName', 'email'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim($request->input($field));
                $request->merge([$field => $field === 'email' ? strtolower($value) : $value]);
            }
        }
        $linkedProfile = $this->profileFor($user);
        $emailRules = ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)];
        if ($linkedProfile?->isFillable('email')) {
            $emailRules[] = Rule::unique($linkedProfile->getTable(), 'email')->ignore($linkedProfile->id);
        }
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => $emailRules,
            'phone' => ['required', 'string', 'max:50'],
            'role' => ['prohibited'], 'platformRole' => ['prohibited'], 'platform_role' => ['prohibited'],
            'status' => ['prohibited'], 'roleId' => ['prohibited'], 'permissions' => ['prohibited'],
        ]);
        $phone = $phones->normalize($data['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid phone number, including the country code.']);
        }
        $identity = DB::table('phone_identities')->where('user_id', $user->id)->first();
        if ($identity && $phone !== $identity->phone) {
            throw ValidationException::withMessages(['phone' => 'Your sign-in phone requires verification before it can be changed.']);
        }
        $others = array_diff($phones->matchingUserIds($phone), [$user->id]);
        if ($others) {
            throw ValidationException::withMessages(['phone' => 'This phone number belongs to another account.']);
        }

        DB::transaction(function () use ($user, $data, $phone, $identity): void {
            $profile = $this->profileFor($user);
            if (! $profile && $user->role === 'systemUser') {
                $profile = new SystemUser;
                $profile->createdBy = $user->id;
            }
            abort_unless($profile || $identity, 422, 'Your linked profile could not be found. Contact another administrator.');
            if ($profile) {
                $profile->firstName = trim($data['firstName']);
                $profile->{$user->role === 'farmer' ? 'surname' : 'lastName'} = trim($data['lastName']);
                $profile->{$user->role === 'farmer' ? 'phone1' : 'phone'} = $phone;
                if ($profile->isFillable('email')) {
                    $profile->email = $data['email'];
                }
                $profile->save();
                $user->roleId = $profile->id;
            }
            $user->email = strtolower(trim($data['email']));
            $user->updatedBy = $user->id;
            $user->save();
            if ($identity) {
                DB::table('phone_identities')->where('user_id', $user->id)->update([
                    'display_name' => trim($data['firstName']).' '.trim($data['lastName']), 'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => 'Profile updated.', 'data' => $this->details($user->fresh())]);
    }

    public function deletionCheck(Request $request, AdminAccountDeletion $deletion): JsonResponse
    {
        return response()->json(['data' => $deletion->check($request->user())]);
    }

    public function destroy(Request $request, AdminAccountDeletion $deletion): JsonResponse
    {
        $request->validate(['confirmation' => ['required', Rule::in(['delete'])]]);
        try {
            return DB::transaction(function () use ($request, $deletion): JsonResponse {
                // Lock the same administrator set in a stable order before rechecking.
                User::whereIn('platform_role', ['admin', 'superAdmin'])->orderBy('id')->lockForUpdate()->get();
                $user = User::lockForUpdate()->findOrFail($request->user()->id);
                $check = $deletion->check($user);
                if (! $check['canDelete']) {
                    return response()->json([...$check, 'data' => $check], 409);
                }
                $user->tokens()->delete();
                DB::table('user_platform_permissions')->where('user_uuid', $user->uuid)->delete();
                DB::table('sessions')->where('userId', $user->id)->delete();
                $user->delete();

                return response()->json(['message' => 'Your account has been deleted.']);
            });
        } catch (QueryException $error) {
            if (str_starts_with((string) $error->getCode(), '23')) {
                return response()->json(['message' => 'Your account is still linked to records and could not be deleted.', 'code' => 'accountInUse'], 409);
            }
            throw $error;
        }
    }

    private function details(User $user): array
    {
        $profile = $this->profileFor($user);
        $identity = DB::table('phone_identities')->where('user_id', $user->id)->first();
        $name = preg_split('/\s+/', trim($identity?->display_name ?? ''), 2);
        $permissions = DB::table('user_platform_permissions as grants')
            ->join('platform_permissions as permissions', 'permissions.id', '=', 'grants.permission_id')
            ->where('grants.user_uuid', $user->uuid)->orderBy('permissions.code')->pluck('permissions.code');

        return [
            'id' => $user->id, 'username' => $user->username, 'email' => $user->email,
            'firstName' => $profile?->firstName ?? ($name[0] ?? ''), 'lastName' => $profile?->lastName ?? $profile?->surname ?? ($name[1] ?? ''),
            'phone' => $identity?->phone ?? $profile?->phone ?? $profile?->phone1 ?? '',
            'phoneReadOnly' => (bool) $identity, 'role' => $user->role,
            'platformRole' => $user->platform_role, 'status' => $user->status,
            'permissions' => $permissions, 'createdAt' => $user->created_at?->toISOString(),
        ];
    }

    private function profileFor(User $user): ?\Illuminate\Database\Eloquent\Model
    {
        if ($user->role === 'extensionOfficer') {
            $officer = ExtensionOfficer::whereKey($user->roleId)->where('email', $user->email)->first();
            if ($officer) {
                return $officer;
            }
        }

        return $user->roleId && in_array($user->role, ['systemUser', 'farmer', 'farmInvitedUser', 'vet', 'extensionOfficer'], true)
            ? $user->profile()->first() : null;
    }
}
