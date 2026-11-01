<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::SYSTEM_USER,
            'roleId' => 0,
            'status' => UserStatus::ACTIVE,
            'createdBy' => null,
            'updatedBy' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function ($user): void {
            if ($user->role !== UserRole::SYSTEM_USER || $user->roleId !== 0) {
                return;
            }

            $profile = SystemUser::create([
                'firstName' => fake()->firstName(),
                'middleName' => null,
                'lastName' => fake()->lastName(),
                'phone' => fake()->unique()->numerify('+2557########'),
                'address' => fake()->address(),
                'status' => UserStatus::ACTIVE,
                'createdBy' => $user->id,
            ]);

            $user->forceFill(['roleId' => $profile->id])->save();
        });
    }
}
