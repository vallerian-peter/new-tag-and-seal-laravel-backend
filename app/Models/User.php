<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'role',
        'roleId',
        'status',
        'createdBy',
        'updatedBy',
    ];

    protected $appends = ['name', 'phone'];

    public function getNameAttribute(): string
    {
        try {
            $phoneIdentity = DB::table('phone_identities')->where('user_id', $this->id)->first();
            if ($phoneIdentity && ! empty($phoneIdentity->display_name)) {
                return trim($phoneIdentity->display_name);
            }

            $profile = $this->roleId ? $this->profile()->first() : null;
            if ($profile) {
                $fullName = trim(($profile->firstName ?? '') . ' ' . ($profile->surname ?? $profile->lastName ?? ''));
                if (! empty($fullName)) {
                    return $fullName;
                }
            }
        } catch (\Throwable $e) {}

        if ($this->username && ! str_starts_with($this->username, 'phone-')) {
            return $this->username;
        }

        return 'User #' . $this->id;
    }

    public function getPhoneAttribute(): ?string
    {
        try {
            $phoneIdentity = DB::table('phone_identities')->where('user_id', $this->id)->first();
            if ($phoneIdentity && ! empty($phoneIdentity->phone)) {
                return $phoneIdentity->phone;
            }

            $profile = $this->roleId ? $this->profile()->first() : null;
            if ($profile) {
                return $profile->phone1 ?? $profile->phone ?? null;
            }
        } catch (\Throwable $e) {}

        return null;
    }

    public function getEmailAttribute($value): string
    {
        if (str_ends_with($value ?? '', '@accounts.invalid')) {
            return '';
        }

        return $value ?? '';
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
            $user->platform_role ??= 'appUser';
        });
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the role of the user.
     */
    // public function getRoleAttribute(): string
    // {
    //     return $this->role;
    // }

    /**
     * Check if user is a system user.
     */
    public function isSystemUser(): bool
    {
        return $this->role === UserRole::SYSTEM_USER;
    }

    /**
     * Check if user is a farmer.
     */
    public function isFarmer(): bool
    {
        return $this->role === UserRole::FARMER;
    }

    /**
     * Check if user is an extension officer.
     */
    public function isExtensionOfficer(): bool
    {
        return $this->role === UserRole::EXTENSION_OFFICER;
    }

    /**
     * Check if user is a vet.
     */
    public function isVet(): bool
    {
        return $this->role === UserRole::VET;
    }

    /**
     * Check if user is a farm invited user.
     */
    public function isFarmInvitedUser(): bool
    {
        return $this->role === UserRole::FARM_INVITED_USER;
    }

    /** Check if user is a transporter. */
    public function isTransporter(): bool
    {
        return $this->role === UserRole::TRANSPORTER;
    }

    /** Check if user is a driver. */
    public function isDriver(): bool
    {
        return $this->role === UserRole::DRIVER;
    }

    /** Check if user is a factory operator. */
    public function isFactoryOperator(): bool
    {
        return $this->role === UserRole::FACTORY_OPERATOR;
    }

    /** Check if user is an MCC operator. */
    public function isMccOperator(): bool
    {
        return $this->role === UserRole::MCC_OPERATOR;
    }

    /** Check if user is a buyer. */
    public function isBuyer(): bool
    {
        return $this->role === UserRole::BUYER;
    }

    /**
     * Check if user is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }

    /**
     * Check if user has one of the specified roles.
     */
    public function hasRole(string|array $roles): bool
    {
        $roles = is_array($roles) ? $roles : [$roles];

        return in_array($this->role, $roles);
    }

    /**
     * Check if user is an admin (system user).
     */
    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::admins());
    }

    /**
     * Check if user is a field worker.
     */
    public function isFieldWorker(): bool
    {
        return $this->hasRole(UserRole::fieldWorkers());
    }

    /**
     * Check if user can manage livestock.
     */
    public function canManageLivestock(): bool
    {
        return $this->hasRole(UserRole::livestockManagers());
    }

    public function hasPlatformPermission(string $permission): bool
    {
        if ($this->platform_role === 'superAdmin') return true;
        if (! Schema::hasTable('platform_permissions') || empty($this->uuid)) {
            return false;
        }

        return DB::table('user_platform_permissions')
            ->join('platform_permissions', 'platform_permissions.id', '=', 'user_platform_permissions.permission_id')
            ->where('user_platform_permissions.user_uuid', $this->uuid)
            ->where('platform_permissions.code', $permission)
            ->exists();
    }

    public function hasAnyPlatformPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPlatformPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the creator of this user.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'createdBy');
    }

    /**
     * Get the updater of this user.
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updatedBy');
    }

    /**
     * Scope to filter by role.
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter active users.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Get the profile data based on role.
     */
    public function profile()
    {
        switch ($this->role) {
            case UserRole::FARMER:
                return $this->belongsTo(Farmer::class, 'roleId');
            case UserRole::SYSTEM_USER:
                return $this->belongsTo(SystemUser::class, 'roleId');
            case UserRole::EXTENSION_OFFICER:
            case UserRole::VET:
                return $this->belongsTo(SystemUser::class, 'roleId');
            case UserRole::FARM_INVITED_USER:
                return $this->belongsTo(FarmUser::class, 'roleId');
            default:
                return $this->belongsTo(Farmer::class, 'roleId');
        }
    }

    /**
     * Get role display name.
     */
    public function getRoleDisplayName(): string
    {
        return UserRole::getDisplayName($this->role);
    }

    /**
     * Get status display name.
     */
    public function getStatusDisplayName(): string
    {
        return UserStatus::getDisplayName($this->status);
    }
}
