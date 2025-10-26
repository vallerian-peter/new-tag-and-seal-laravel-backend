# Role Management System Documentation

## Overview
This system implements a clean, well-defined role-based access control (RBAC) using constants and enums for better code maintainability and type safety.

---

## Role Structure

### Available Roles

| Role | Constant | Description | Profile Type |
|------|----------|-------------|--------------|
| **System User** | `UserRole::SYSTEM_USER` | Full system administrator | SystemUser |
| **Farmer** | `UserRole::FARMER` | Farm owner or manager | Farmer |
| **Extension Officer** | `UserRole::EXTENSION_OFFICER` | Agricultural extension services | SystemUser |
| **Veterinarian** | `UserRole::VET` | Animal health professional | SystemUser |
| **Farm Invited User** | `UserRole::FARM_INVITED_USER` | Guest user with limited access | SystemUser |

---

## File Structure

```
app/
├── Enums/
│   ├── UserRole.php           # Role constants and helper methods
│   └── UserStatus.php         # Status constants
├── Models/
│   └── User.php               # Enhanced with role checking methods
├── Http/
│   ├── Controllers/
│   │   └── Auth/
│   │       └── AuthController.php  # Uses role constants
│   └── Middleware/
│       └── CheckRole.php      # Role-based access control
└── routes/
    └── api.php                # Routes with role protection
```

---

## UserRole Enum (app/Enums/UserRole.php)

### Constants
```php
UserRole::SYSTEM_USER          // 'systemUser'
UserRole::FARMER               // 'farmer'
UserRole::EXTENSION_OFFICER    // 'extensionOfficer'
UserRole::VET                  // 'vet'
UserRole::FARM_INVITED_USER    // 'farmInvitedUser'
```

### Helper Methods

#### Get All Roles
```php
UserRole::all()
// Returns: ['systemUser', 'farmer', 'extensionOfficer', 'vet', 'farmInvitedUser']
```

#### Get Role Groups
```php
UserRole::admins()
// Returns: ['systemUser']

UserRole::livestockManagers()
// Returns: ['farmer', 'vet', 'extensionOfficer']

UserRole::fieldWorkers()
// Returns: ['extensionOfficer', 'vet']

UserRole::systemUserProfiles()
// Returns: ['systemUser', 'extensionOfficer', 'vet', 'farmInvitedUser']
```

#### Validation
```php
UserRole::isValid('farmer')
// Returns: true

UserRole::isValid('invalid_role')
// Returns: false
```

#### Display Names
```php
UserRole::getDisplayName(UserRole::FARMER)
// Returns: "Farmer"

UserRole::getDescription(UserRole::FARMER)
// Returns: "Farm owner or manager with access to farm and livestock management"
```

---

## User Model Methods

### Role Checking Methods

```php
// Check specific role
$user->isFarmer()              // bool
$user->isSystemUser()          // bool
$user->isExtensionOfficer()    // bool
$user->isVet()                 // bool
$user->isFarmInvitedUser()     // bool

// Check multiple roles
$user->hasRole(UserRole::FARMER)                    // bool
$user->hasRole([UserRole::FARMER, UserRole::VET])   // bool

// Check role groups
$user->isAdmin()               // Has admin privileges
$user->isFieldWorker()         // Is extension officer or vet
$user->canManageLivestock()    // Can manage livestock (farmer, vet, ext. officer)

// Status checking
$user->isActive()              // bool

// Display helpers
$user->getRoleDisplayName()    // "Farmer"
$user->getStatusDisplayName()  // "Active"
```

### Usage Examples

```php
// In controller
if ($user->isFarmer()) {
    // Farmer-specific logic
}

if ($user->hasRole([UserRole::FARMER, UserRole::VET])) {
    // Logic for farmers and vets
}

if ($user->canManageLivestock()) {
    // Allow livestock management
}
```

---

## Route Protection

### Using Middleware

```php
use App\Enums\UserRole;

// Single role
Route::middleware('check.role:' . UserRole::SYSTEM_USER)->group(function () {
    // Only system users
});

// Multiple roles
Route::middleware('check.role:' . UserRole::FARMER . ',' . UserRole::SYSTEM_USER)
    ->group(function () {
        // Farmers and system users
    });
```

### Current Route Structure

```php
// Public routes (no auth)
POST /api/auth/register
POST /api/auth/login

// Protected routes (auth required)
POST /api/auth/logout
GET  /api/auth/profile
POST /api/auth/change-password

// Admin routes (system user only)
GET    /api/users
POST   /api/users
GET    /api/users/statistics
GET    /api/users/{user}
PUT    /api/users/{user}
DELETE /api/users/{user}

// Farmer routes (farmer + admin)
GET    /api/farmers/*

// Extension officer routes (ext. officer + admin)
GET    /api/extension-officers/*

// Vet routes (vet + admin)
GET    /api/vets/*
```

---

## AuthController Usage

### Registration
```php
// Validation uses role constants
'role' => ['required', 'string', Rule::in(UserRole::all())],

// Role-based profile creation
switch ($request->role) {
    case UserRole::FARMER:
        // Create Farmer profile
        break;
    case UserRole::SYSTEM_USER:
    case UserRole::EXTENSION_OFFICER:
    case UserRole::VET:
    case UserRole::FARM_INVITED_USER:
        // Create SystemUser profile
        break;
}
```

### Profile Loading
```php
private function getProfileData(User $user)
{
    switch ($user->role) {
        case UserRole::FARMER:
            return Farmer::find($user->roleId);
        case UserRole::SYSTEM_USER:
        case UserRole::EXTENSION_OFFICER:
        case UserRole::VET:
        case UserRole::FARM_INVITED_USER:
            return SystemUser::find($user->roleId);
        default:
            return null;
    }
}
```

---

## Best Practices

### ✅ DO

```php
// Use constants
if ($user->role === UserRole::FARMER) { }

// Use helper methods
if ($user->isFarmer()) { }
if ($user->hasRole(UserRole::admins())) { }

// Use enums in validation
Rule::in(UserRole::all())

// Use type-safe comparisons
$user->role === UserRole::SYSTEM_USER
```

### ❌ DON'T

```php
// Don't use hardcoded strings
if ($user->role === 'farmer') { }  // BAD

// Don't compare without constants
if ($user->role == 'systemUser') { }  // BAD

// Don't create new role strings
$role = 'new_role';  // BAD - use constants
```

---

## Adding New Roles

### Step 1: Add to UserRole Enum
```php
// app/Enums/UserRole.php
public const NEW_ROLE = 'newRole';

public static function all(): array
{
    return [
        // ... existing roles
        self::NEW_ROLE,
    ];
}
```

### Step 2: Add Helper Method in User Model
```php
// app/Models/User.php
public function isNewRole(): bool
{
    return $this->role === UserRole::NEW_ROLE;
}
```

### Step 3: Update AuthController
```php
// Add profile creation logic
case UserRole::NEW_ROLE:
    // Create profile
    break;

// Add profile loading logic
case UserRole::NEW_ROLE:
    return NewRoleProfile::find($user->roleId);
```

### Step 4: Update Routes
```php
// Add protected routes
Route::prefix('new-roles')
    ->middleware('check.role:' . UserRole::NEW_ROLE . ',' . UserRole::SYSTEM_USER)
    ->group(function () {
        // Routes
    });
```

---

## Testing Role System

### Test Role Validation
```php
// Valid role
$request = ['role' => UserRole::FARMER];
// Should pass validation

// Invalid role
$request = ['role' => 'invalid_role'];
// Should fail validation
```

### Test Role Checking
```php
$user = User::factory()->create(['role' => UserRole::FARMER]);

assert($user->isFarmer() === true);
assert($user->isSystemUser() === false);
assert($user->hasRole(UserRole::FARMER) === true);
assert($user->canManageLivestock() === true);
```

### Test Middleware
```php
// As farmer
$response = $this->actingAs($farmer)
    ->get('/api/farmers');
// Should return 200

// As unauthorized user
$response = $this->actingAs($vet)
    ->get('/api/users');
// Should return 403
```

---

## Benefits

✅ **Type Safety**: Constants prevent typos  
✅ **Maintainability**: Change role names in one place  
✅ **Readability**: Clear, self-documenting code  
✅ **IDE Support**: Autocomplete for role constants  
✅ **Validation**: Centralized role validation  
✅ **Flexibility**: Easy to add new roles  
✅ **Grouping**: Logical role groups for common permissions  

---

## Role Permissions Matrix

| Feature | System User | Farmer | Ext. Officer | Vet | Farm Invited |
|---------|------------|--------|--------------|-----|--------------|
| User Management | ✅ | ❌ | ❌ | ❌ | ❌ |
| Farm Management | ✅ | ✅ | ✅ | ❌ | View Only |
| Livestock Management | ✅ | ✅ | ✅ | ✅ | View Only |
| Health Records | ✅ | ✅ | ✅ | ✅ | ❌ |
| Reports | ✅ | ✅ | ✅ | ✅ | View Only |
| System Settings | ✅ | ❌ | ❌ | ❌ | ❌ |

---

## Summary

The role system is now clean, well-defined, and easy to maintain with:
- ✅ Centralized role constants
- ✅ Helper methods for role checking
- ✅ Type-safe code
- ✅ Clear documentation
- ✅ Easy extensibility

