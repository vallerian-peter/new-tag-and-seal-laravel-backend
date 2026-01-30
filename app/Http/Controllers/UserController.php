<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * UserController - Admin User Management
 * 
 * IMPORTANT: Frontend user registration is handled by AuthController::register()
 * This controller is ONLY for admin operations via /api/v1/admin/users endpoints
 * 
 * Frontend Flow (DO NOT TOUCH):
 * - User registration: AuthController::register() -> Creates User + Profile (Farmer/SystemUser/etc)
 * - User login: AuthController::login()
 * - User sync: SyncController::splashSync() and postSync()
 * 
 * Admin Flow (This Controller):
 * - List users: GET /api/v1/admin/users
 * - Create user: POST /api/v1/admin/users (admin portal only)
 * - View user: GET /api/v1/admin/users/{id}
 * - Update user: PUT /api/v1/admin/users/{id}
 * - Delete user: DELETE /api/v1/admin/users/{id}
 * - Statistics: GET /api/v1/admin/users/statistics
 */
class UserController extends Controller
{
    // ========================================================================
    // ADMIN METHODS - Used by Admin Portal (/api/v1/admin/users)
    // These methods require 'systemUser' role and don't interfere with frontend
    // ========================================================================

    /**
     * Admin: Display a listing of users.
     * GET /api/v1/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Filter by role
        if ($request->has('role')) {
            $query->byRole($request->role);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->byStatus($request->status);
        }

        // Filter active users only
        if ($request->boolean('active_only')) {
            $query->active();
        }

        $users = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'status' => true,
            'message' => 'Users retrieved successfully',
            'data' => $users
        ]);
    }

    /**
     * Admin: Create a new user (Admin Portal Only).
     * POST /api/v1/admin/users
     * 
     * Note: Frontend uses AuthController::register() instead.
     * This method is for admin portal to create users manually.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|string|in:systemUser,farmer,extensionOfficer,vet',
            'roleId' => 'required|integer',
            'status' => 'sometimes|string|in:active,notActive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'username' => $request->username,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'roleId' => $request->roleId,
            'status' => $request->status ?? 'active',
            'createdBy' => Auth::id(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    /**
     * Admin: Display the specified user.
     * GET /api/v1/admin/users/{id}
     */
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => 'User retrieved successfully',
            'data' => $user
        ]);
    }

    /**
     * Admin: Update the specified user.
     * PUT /api/v1/admin/users/{id}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'sometimes|string|unique:users,username,' . $user->id,
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:6',
            'role' => 'sometimes|string|in:systemUser,farmer,extensionOfficer,vet',
            'roleId' => 'sometimes|integer',
            'status' => 'sometimes|string|in:active,notActive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = $request->only(['username', 'email', 'role', 'roleId', 'status']);

        if ($request->has('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $updateData['updatedBy'] = Auth::id();
        
        $user->update($updateData);

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    /**
     * Admin: Remove the specified user.
     * DELETE /api/v1/admin/users/{id}
     */
    public function destroy(User $user): JsonResponse
    {
        // Prevent deletion of system users
        if ($user->isSystemUser()) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot delete system users'
            ], 403);
        }

        $user->delete();

        return response()->json([
            'status' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Admin: Get user statistics.
     * GET /api/v1/admin/users/statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'system_users' => User::byRole('systemUser')->count(),
            'farmers' => User::byRole('farmer')->count(),
            'extension_officers' => User::byRole('extensionOfficer')->count(),
            'vets' => User::byRole('vet')->count(),
            'active_users' => User::active()->count(),
            'inactive_users' => User::byStatus('notActive')->count(),
        ];

        return response()->json([
            'status' => true,
            'message' => 'User statistics retrieved successfully',
            'data' => $stats
        ]);
    }
}
