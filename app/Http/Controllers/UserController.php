<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Filter by profile type
        if ($request->has('profile')) {
            $query->byProfile($request->profile);
        }

        // Filter by status
        if ($request->has('status_id')) {
            $query->byStatus($request->status_id);
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
     * Store a newly created user.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|email|unique:users,username',
            'password' => 'required|string|min:6',
            'profile' => 'required|string|in:SystemUser,Farmer',
            'profile_id' => 'required|integer',
            'status_id' => 'integer|min:1',
            'state_id' => 'nullable|integer',
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
            'password' => Hash::make($request->password),
            'profile' => $request->profile,
            'profile_id' => $request->profile_id,
            'status_id' => $request->status_id ?? 1,
            'created_by' => Auth::user()->id ?? 1,
            'state_id' => $request->state_id,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'User created successfully',
            'data' => $user
        ], 201);
    }

    /**
     * Display the specified user.
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
     * Update the specified user.
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'username' => 'sometimes|email|unique:users,username,' . $user->id,
            'password' => 'sometimes|string|min:6',
            'profile' => 'sometimes|string|in:SystemUser,Farmer',
            'profile_id' => 'sometimes|integer',
            'status_id' => 'sometimes|integer|min:1',
            'state_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $updateData = $request->only(['username', 'profile', 'profile_id', 'status_id', 'state_id']);

        if ($request->has('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $updateData['updated_by'] = Auth::user()->id ?? $user->updated_by;
        
        $user->update($updateData);

        return response()->json([
            'status' => true,
            'message' => 'User updated successfully',
            'data' => $user
        ]);
    }

    /**
     * Remove the specified user.
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
     * Get user statistics.
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'system_users' => User::byProfile('SystemUser')->count(),
            'farmers' => User::byProfile('Farmer')->count(),
            'active_users' => User::active()->count(),
            'inactive_users' => User::byStatus(0)->count(),
        ];

        return response()->json([
            'status' => true,
            'message' => 'User statistics retrieved successfully',
            'data' => $stats
        ]);
    }
}
