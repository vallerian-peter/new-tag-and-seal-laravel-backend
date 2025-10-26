# Authentication API Documentation

## Overview
This API provides authentication and user management functionality with support for multiple user roles:
- **Farmer**: Farm owners and managers
- **SystemUser**: System administrators
- **ExtensionOfficer**: Agricultural extension officers
- **Vet**: Veterinary professionals
- **FarmInvitedUser**: Users invited to access specific farms

## Base URL
```
http://your-domain.com/api
```

## Authentication
All protected routes require a Bearer token in the Authorization header:
```
Authorization: Bearer {your_token}
```

---

## Public Endpoints (No Authentication Required)

### 1. Register User
Create a new user account with role-specific profile.

**Endpoint:** `POST /auth/register`

**Request Body:**
```json
{
    "username": "john_farmer",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "role": "farmer",
    "first_name": "John",
    "middle_name": "Doe",
    "surname": "Smith",
    "phone1": "+255712345678",
    "phone2": "+255787654321",
    "gender": "male",
    "date_of_birth": "1990-01-15",
    "address": "123 Main Street, Dar es Salaam",
    "farmer_type": "individual",
    "country_id": 1
}
```

**Validation Rules:**
- `username`: required, string, unique
- `email`: required, email, unique
- `password`: required, string, min:8, confirmed
- `role`: required, one of: `farmer`, `system_user`, `farm_invited_user`, `extension_officer`, `vet`
- `first_name`: required if role is farmer/system_user/extension_officer/vet
- `surname`: required if role is farmer
- `last_name`: required if role is system_user/extension_officer/vet
- `phone1`: required if role is farmer
- `phone`: required if role is system_user/extension_officer/vet
- `gender`: required if role is farmer, one of: `male`, `female`

**Response (201 Created):**
```json
{
    "status": true,
    "message": "User registered successfully",
    "data": {
        "user": {
            "id": 1,
            "username": "john_farmer",
            "email": "john@example.com",
            "role": "farmer",
            "status": "active"
        },
        "profile": {
            "id": 1,
            "farmerNo": "FMR202500001",
            "firstName": "John",
            "middleName": "Doe",
            "surname": "Smith",
            "phone1": "+255712345678",
            "email": "john@example.com",
            "gender": "male",
            "dateOfBirth": "1990-01-15",
            "status": "active"
        },
        "access_token": "1|abc123xyz...",
        "token_type": "Bearer"
    }
}
```

### 2. Login
Authenticate user with username/email and password.

**Endpoint:** `POST /auth/login`

**Request Body:**
```json
{
    "login": "john_farmer",
    "password": "password123",
    "device_name": "Mobile App"
}
```

**Validation Rules:**
- `login`: required, string (can be username or email)
- `password`: required, string
- `device_name`: optional, string

**Response (200 OK):**
```json
{
    "status": true,
    "message": "Login successful",
    "data": {
        "user": {
            "id": 1,
            "username": "john_farmer",
            "email": "john@example.com",
            "role": "farmer",
            "role_id": 1,
            "status": "active"
        },
        "profile": {
            "id": 1,
            "farmerNo": "FMR202500001",
            "firstName": "John",
            "surname": "Smith",
            "phone1": "+255712345678"
        },
        "access_token": "2|xyz789abc...",
        "token_type": "Bearer"
    }
}
```

**Error Responses:**
- **401 Unauthorized:** Invalid credentials
- **403 Forbidden:** Account deactivated

---

## Protected Endpoints (Authentication Required)

### 3. Get User Profile
Retrieve authenticated user's profile information.

**Endpoint:** `GET /auth/profile`

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
    "status": true,
    "message": "Profile retrieved successfully",
    "data": {
        "user": {
            "id": 1,
            "username": "john_farmer",
            "email": "john@example.com",
            "role": "farmer",
            "role_id": 1,
            "status": "active"
        },
        "profile": {
            "id": 1,
            "farmerNo": "FMR202500001",
            "firstName": "John",
            "surname": "Smith"
        }
    }
}
```

### 4. Change Password
Update user password.

**Endpoint:** `POST /auth/change-password`

**Headers:**
```
Authorization: Bearer {token}
```

**Request Body:**
```json
{
    "current_password": "password123",
    "new_password": "newpassword456",
    "new_password_confirmation": "newpassword456"
}
```

**Response (200 OK):**
```json
{
    "status": true,
    "message": "Password changed successfully"
}
```

### 5. Logout
Revoke current access token.

**Endpoint:** `POST /auth/logout`

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
    "status": true,
    "message": "Logged out successfully"
}
```

### 6. Logout from All Devices
Revoke all user's access tokens.

**Endpoint:** `POST /auth/logout-all`

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
    "status": true,
    "message": "Logged out from all devices successfully"
}
```

### 7. Get Authenticated User
Get current authenticated user details.

**Endpoint:** `GET /user`

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
    "status": true,
    "message": "User retrieved successfully",
    "data": {
        "id": 1,
        "username": "john_farmer",
        "email": "john@example.com",
        "role": "farmer",
        "role_id": 1,
        "status": "active"
    }
}
```

---

## User Management Endpoints (System User Only)

### 8. List Users
Get paginated list of users with optional filters.

**Endpoint:** `GET /users`

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `profile`: Filter by profile type (optional)
- `status_id`: Filter by status (optional)
- `active_only`: Show only active users (boolean, optional)

**Response (200 OK):**
```json
{
    "status": true,
    "message": "Users retrieved successfully",
    "data": {
        "current_page": 1,
        "data": [...],
        "total": 50
    }
}
```

### 9. Get User Statistics
Get user statistics by role and status.

**Endpoint:** `GET /users/statistics`

**Headers:**
```
Authorization: Bearer {token}
```

**Response (200 OK):**
```json
{
    "status": true,
    "message": "User statistics retrieved successfully",
    "data": {
        "total_users": 150,
        "system_users": 5,
        "farmers": 120,
        "active_users": 145,
        "inactive_users": 5
    }
}
```

---

## Role-Based Access Control

### Roles and Permissions

| Role | Access |
|------|--------|
| **farmer** | Own profile, farms, livestock |
| **system_user** | All resources, user management |
| **extension_officer** | Farmer support, advisory services |
| **vet** | Livestock health management |
| **farm_invited_user** | Specific farm access |

### Middleware Usage
Routes can be protected using the `check.role` middleware:

```php
Route::middleware('check.role:farmer,system_user')->group(function () {
    // Routes accessible by farmers and system users
});
```

---

## Error Responses

### Common Error Formats

**Validation Error (422):**
```json
{
    "status": false,
    "message": "Validation failed",
    "errors": {
        "email": ["The email has already been taken."],
        "password": ["The password must be at least 8 characters."]
    }
}
```

**Unauthorized (401):**
```json
{
    "status": false,
    "message": "Unauthenticated"
}
```

**Forbidden (403):**
```json
{
    "status": false,
    "message": "Unauthorized. You do not have permission to access this resource."
}
```

**Server Error (500):**
```json
{
    "status": false,
    "message": "Registration failed: Database connection error"
}
```

---

## Testing with cURL

### Register a Farmer
```bash
curl -X POST http://your-domain.com/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "username": "john_farmer",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "role": "farmer",
    "first_name": "John",
    "surname": "Smith",
    "phone1": "+255712345678",
    "gender": "male"
  }'
```

### Login
```bash
curl -X POST http://your-domain.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "login": "john_farmer",
    "password": "password123"
  }'
```

### Get Profile (with token)
```bash
curl -X GET http://your-domain.com/api/auth/profile \
  -H "Authorization: Bearer {your_token}"
```

---

## Architecture

### Folder Structure
```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Auth/
│   │   │   └── AuthController.php
│   │   └── UserController.php
│   ├── Middleware/
│   │   └── CheckRole.php
│   └── Requests/
│       └── Auth/
├── Models/
│   ├── User.php
│   ├── Farmer.php
│   └── SystemUser.php
```

### Design Principles
1. **Modular Structure**: Controllers organized by domain (Auth, User, etc.)
2. **Clean Code**: Simple, readable methods with single responsibility
3. **Role-Based Access**: Flexible middleware for permission control
4. **Consistent Responses**: Standard JSON response format
5. **Profile Separation**: User account separated from profile data

---

## Next Steps
1. Implement farm management endpoints
2. Add livestock management endpoints
3. Create reporting and analytics endpoints
4. Add file upload for profile pictures
5. Implement email verification

