<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * UserController - Backend system-user management
 * 
 * IMPORTANT: Frontend user registration is handled by AuthController::register()
 * Backend user-management endpoints under /api/v1/users.
 * 
 * Frontend Flow (DO NOT TOUCH):
 * - User registration: AuthController::register() -> Creates User + Profile (Farmer/SystemUser/etc)
 * - User login: AuthController::login()
 * - User sync: SyncController::splashSync() and postSync()
 * 
 * The separate Filament Portal writes to the shared database directly and
 * does not call this controller.
 */
class UserController extends Controller
{
    // ========================================================================
    // Backend system-user API methods. These are not used by the Portal.
    // These methods require 'systemUser' role and don't interfere with frontend
    // ========================================================================

    /**
     * Admin: Display a listing of users.
     * GET /api/v1/users
     */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPlatformPermission('accounts.view'), 403);
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

        $users = $query->with('profile')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => true,
            'message' => 'Users retrieved successfully',
            'data' => $users
        ]);
    }

    /**
     * Create a user through the backend system-user API.
     * POST /api/v1/users
     * 
     * Note: Frontend uses AuthController::register() instead.
     * The Portal uses its own Filament resource instead.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string',
            'roleId' => 'nullable|integer',
            'status' => 'nullable|string|in:active,notActive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->all();
        if (($data['role'] ?? null) === 'systemUser') {
            abort_unless($request->user()->platform_role === 'superAdmin', 403);
        }

        // Auto-create SystemUser profile if needed
        if (in_array($data['role'], [\App\Enums\UserRole::SYSTEM_USER, \App\Enums\UserRole::EXTENSION_OFFICER, \App\Enums\UserRole::VET]) && empty($data['roleId'])) {
            $systemUser = \App\Models\SystemUser::create([
                'firstName' => $data['username'], // Fallback
                'lastName' => $data['username'],  // Fallback
                'status' => 'active',
            ]);
            $data['roleId'] = $systemUser->id;
        }

        $user = User::create($data);
        if ($user->role === 'systemUser') {
            $user->forceFill(['platform_role' => 'admin'])->save();
        }
        $user->load('profile');

        return response()->json([
            'status' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    /**
     * Admin: Update the specified user.
     * PUT /api/v1/users/{id}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->hasPlatformPermission('accounts.view'), 403);
        return response()->json([
            'status' => true,
            'message' => 'User retrieved successfully',
            'data' => $user
        ]);
    }

    /**
     * Update the specified user.
     * PUT /api/v1/users/{id}
     */
    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
        abort_if($request->user()->is($user) && ($request->has('role') || $request->has('status')), 403);
        if ($user->platform_role === 'superAdmin' || ($request->input('role') === 'systemUser' && $user->role !== 'systemUser')) {
            abort_unless($request->user()->platform_role === 'superAdmin', 403);
        }
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
        if (($updateData['status'] ?? null) === 'notActive' || isset($updateData['password'])) {
            $user->tokens()->delete();
        }

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    /**
     * Admin: Remove the specified user.
     * DELETE /api/v1/users/{id}
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->hasPlatformPermission('permissions.manage'), 403);
        abort_if($request->user()->is($user), 403);
        if ($user->platform_role === 'superAdmin') {
            abort_unless($request->user()->platform_role === 'superAdmin', 403);
            abort_if(User::where('platform_role', 'superAdmin')->where('status', 'active')->count() <= 1, 403);
        }
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
     * GET /api/v1/users/statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPlatformPermission('accounts.view'), 403);
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
