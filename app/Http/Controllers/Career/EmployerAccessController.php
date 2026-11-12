<?php

namespace App\Http\Controllers\Career;

use App\Http\Controllers\Controller;

use App\Models\User;
use App\Services\PhoneAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployerAccessController extends Controller
{
    private function requireOwner(Request $request): void
    {
        abort_unless(DB::table('feature_memberships')->where('user_id', $request->user()->id)->where('feature', 'jobOwner')->exists(), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireOwner($request);
        $members = DB::table('employer_access_grants')->join('users', 'users.id', '=', 'employer_access_grants.member_user_id')
            ->where('employer_access_grants.owner_user_id', $request->user()->id)
            ->get(['users.id', 'users.username', 'employer_access_grants.permissions'])
            ->map(function ($member) { $member->permissions = json_decode($member->permissions, true); return $member; });
        return response()->json(['data' => $members]);
    }

    public function search(Request $request, PhoneAuthentication $phones): JsonResponse
    {
        $this->requireOwner($request);
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);
        $phone = $phones->normalize($data['phone']);
        if (! $phone) return response()->json(['message' => 'Invalid phone number.'], 422);
        $identity = DB::table('phone_identities')->where('phone', $phone)->first();
        $user = $identity ? User::find($identity->user_id) : null;
        if (! $user || ! $user->isActive() || $user->is($request->user())) return response()->json(['message' => 'Member not found.'], 404);
        $grant = DB::table('employer_access_grants')->where('owner_user_id', $request->user()->id)->where('member_user_id', $user->id)->value('permissions');
        return response()->json(['data' => ['id' => $user->id, 'name' => $identity->display_name ?: $user->username, 'permissions' => $grant ? json_decode($grant, true) : []]]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->requireOwner($request);
        abort_if($request->user()->is($user), 403);
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(['jobs.view', 'jobs.manage', 'applications.view', 'applications.manage'])],
        ]);
        if ($data['permissions'] && ! in_array('jobs.view', $data['permissions'], true)) return response()->json(['message' => 'Job access is required for other permissions.'], 422);
        if (in_array('applications.manage', $data['permissions'], true) && ! in_array('applications.view', $data['permissions'], true)) return response()->json(['message' => 'Application viewing is required to manage applications.'], 422);
        DB::transaction(function () use ($request, $user, $data): void {
            $query = DB::table('employer_access_grants')->where('owner_user_id', $request->user()->id)->where('member_user_id', $user->id);
            $before = (clone $query)->value('permissions');
            DB::table('employer_access_grants')->upsert([[
                'owner_user_id' => $request->user()->id, 'member_user_id' => $user->id,
                'permissions' => json_encode($data['permissions'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
            ]], ['owner_user_id', 'member_user_id'], ['permissions', 'updated_at']);
            DB::table('audit_logs')->insert([
                'uuid' => (string) Str::uuid(), 'actor_uuid' => $request->user()->uuid, 'action' => 'employer.permissions.updated',
                'entity_type' => 'user', 'entity_uuid' => $user->uuid, 'before_json' => $before,
                'after_json' => json_encode(['ownerUserId' => $request->user()->id, 'permissions' => $data['permissions']], JSON_THROW_ON_ERROR),
                'request_uuid' => (string) Str::uuid(), 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(), 'created_at' => now(),
            ]);
        });
        return response()->json(['data' => ['userId' => $user->id, 'permissions' => $data['permissions']]]);
    }
}
