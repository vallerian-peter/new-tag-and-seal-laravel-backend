<?php

namespace App\Services\Dairy;

use App\Models\FactoryOperator;
use App\Models\User;
use App\Services\PhoneAuthentication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * People who work for a factory. A member signs in to the app with their
 * phone: an existing account on that phone is reused, otherwise a new one is
 * made that waits for its first PIN, like a driver's.
 */
class FactoryMemberService
{
    public const ROLES = ['operator', 'manager'];

    public function __construct(private readonly PhoneAuthentication $phones) {}

    /**
     * Adds the person signing in with [phone] to the factory. An account
     * already on that phone is reused; a phone already on this factory, or an
     * ID number another member has, is refused.
     */
    public function add(string $factoryUuid, string $name, string $phone, string $role, ?string $staffId = null, array $profile = []): FactoryOperator
    {
        $normalized = $this->validPhone($phone);
        $userId = $this->phones->matchingUserIds($normalized)[0] ?? null;
        if ($userId && ($existing = FactoryOperator::query()->where('factory_uuid', $factoryUuid)->where('user_id', $userId)->first())) {
            throw ValidationException::withMessages(['phone' => 'This phone already belongs to '
                .($existing->role === 'manager' ? 'a manager' : 'a user').' of this factory. Edit that member instead.']);
        }
        $this->assertUniqueIdentity($factoryUuid, $profile);
        $userId ??= $this->createUser($normalized, $name);

        return FactoryOperator::create([
            ...$profile,
            'factory_uuid' => $factoryUuid,
            'user_id' => $userId,
            'staff_id' => $staffId,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /**
     * Updates a member. A new [phone] moves their app sign-in to that number,
     * so it must not be another account's sign-in.
     */
    public function update(FactoryOperator $member, array $data, ?string $phone = null): FactoryOperator
    {
        $this->assertUniqueIdentity($member->factory_uuid, [...$member->only(['identity_card_type_id', 'identity_number']), ...$data], $member);
        if ($phone !== null) {
            $this->changePhone($member, $this->validPhone($phone));
        }
        $member->update($data);

        return $member->fresh();
    }

    private function changePhone(FactoryOperator $member, string $phone): void
    {
        $others = array_diff($this->phones->matchingUserIds($phone), [$member->user_id]);
        if ($others) {
            throw ValidationException::withMessages(['phone' => 'This phone number is already used by another account.']);
        }
        $identity = DB::table('phone_identities')->where('user_id', $member->user_id);
        if ($identity->exists()) {
            $identity->update(['phone' => $phone, 'updated_at' => now()]);

            return;
        }
        DB::table('phone_identities')->insert([
            'user_id' => $member->user_id,
            'phone' => $phone,
            'display_name' => trim(implode(' ', array_filter([$member->first_name, $member->middle_name, $member->last_name]))) ?: null,
            'pin_hash' => Hash::make(Str::random(12)),
            'pin_pending' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validPhone(string $phone): string
    {
        return $this->phones->normalize($phone)
            ?? throw ValidationException::withMessages(['phone' => 'Enter a valid phone number, for example 0754 123 456.']);
    }

    /** One member per ID document on a factory. */
    private function assertUniqueIdentity(string $factoryUuid, array $profile, ?FactoryOperator $except = null): void
    {
        $number = trim((string) ($profile['identity_number'] ?? ''));
        if ($number === '' || empty($profile['identity_card_type_id'])) {
            return;
        }
        $taken = FactoryOperator::query()->where('factory_uuid', $factoryUuid)
            ->where('identity_card_type_id', $profile['identity_card_type_id'])->where('identity_number', $number)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['identity_number' => 'Another member of this factory already has this ID number.']);
        }
    }

    /** Members with their sign-in name and phone. */
    public function list(string $factoryUuid): Collection
    {
        return DB::table('factory_operators as fo')
            ->where('fo.factory_uuid', $factoryUuid)
            ->select('fo.uuid', 'fo.user_id', 'fo.staff_id', 'fo.role', 'fo.is_active', 'fo.created_at', 'fo.first_name', 'fo.middle_name', 'fo.last_name', 'fo.identity_card_type_id', 'fo.identity_number', 'fo.tin_number')
            ->selectSub($this->identity('display_name'), 'name')
            ->selectSub($this->identity('phone'), 'phone')
            ->orderByDesc('fo.role')
            ->orderBy('fo.id')
            ->get()
            ->map(function ($row) {
                $row->name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name]))) ?: $row->name;
                $row->is_active = (bool) $row->is_active;

                return $row;
            });
    }

    private function identity(string $field)
    {
        return DB::table('phone_identities')->select($field)
            ->whereColumn('phone_identities.user_id', 'fo.user_id')
            ->orderBy('phone_identities.id')
            ->limit(1);
    }

    private function createUser(string $phone, string $name): int
    {
        $id = (string) Str::uuid();
        $user = new User;
        $user->forceFill([
            'username' => 'factory-'.$id,
            'email' => $id.'@accounts.invalid',
            'password' => Str::random(64),
            'role' => 'appUser',
            'roleId' => 0,
            'status' => 'active',
        ])->save();
        DB::table('phone_identities')->insert([
            'user_id' => $user->id,
            'phone' => $phone,
            'display_name' => trim($name) === '' ? null : trim($name),
            'pin_hash' => Hash::make(Str::random(12)),
            'pin_pending' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->id;
    }
}
