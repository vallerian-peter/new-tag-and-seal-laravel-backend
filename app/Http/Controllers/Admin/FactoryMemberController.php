<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FactoryOperator;
use App\Models\TransporterCompany;
use App\Services\Dairy\FactoryMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Operators and managers of a factory, managed from the portal. */
class FactoryMemberController extends Controller
{
    public function __construct(private readonly FactoryMemberService $members) {}

    /** GET /api/admin/dairy/factories/{uuid}/members */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $this->factory($request, $uuid);

        return response()->json(['status' => true, 'data' => $this->members->list($uuid), 'identity_card_types' => DB::table('identity_card_types')->orderBy('name')->get(['id', 'name'])]);
    }

    /**
     * POST /api/admin/dairy/factories/{uuid}/members
     * Body: personal names, phone, identification, optional TIN, and role (operator|manager).
     */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $this->factory($request, $uuid);
        $request->merge(['tin_number' => TransporterCompany::normaliseTin($request->input('tin_number'))]);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'identity_card_type_id' => ['required', 'integer', 'exists:identity_card_types,id'],
            'identity_number' => ['required', 'string', 'max:100'],
            'tin_number' => ['nullable', 'regex:/^\d{3}-\d{3}-\d{3}$/'],
            'phone' => ['required', 'string', 'max:32'],
            'role' => ['required', Rule::in(FactoryMemberService::ROLES)],
            'staff_id' => ['nullable', 'string', 'max:50'],
        ]);
        $profile = Arr::only($data, ['first_name', 'middle_name', 'last_name', 'identity_card_type_id', 'identity_number', 'tin_number']);
        $name = implode(' ', array_filter([$data['first_name'], $data['middle_name'] ?? null, $data['last_name']]));
        $member = DB::transaction(fn () => $this->members->add($uuid, $name, $data['phone'], $data['role'], $data['staff_id'] ?? null, $profile));

        return response()->json([
            'status' => true,
            'data' => $this->members->list($uuid)->firstWhere('uuid', $member->uuid),
        ], 201);
    }

    /**
     * PATCH /api/admin/dairy/factories/{uuid}/members/{memberUuid}
     * Body: names?, phone?, identification?, tin_number?, role?, is_active?, staff_id?
     * A new phone moves the member's app sign-in to that number.
     */
    public function update(Request $request, string $uuid, string $memberUuid): JsonResponse
    {
        $this->factory($request, $uuid);
        $member = FactoryOperator::query()->where('factory_uuid', $uuid)->where('uuid', $memberUuid)->firstOrFail();
        if ($request->has('tin_number')) {
            $request->merge(['tin_number' => TransporterCompany::normaliseTin($request->input('tin_number'))]);
        }
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'identity_card_type_id' => ['sometimes', 'required', 'integer', 'exists:identity_card_types,id'],
            'identity_number' => ['sometimes', 'required', 'string', 'max:100'],
            'tin_number' => ['sometimes', 'nullable', 'regex:/^\d{3}-\d{3}-\d{3}$/'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'role' => ['sometimes', Rule::in(FactoryMemberService::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'staff_id' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
        $demoted = (isset($data['role']) && $data['role'] !== 'manager') || (array_key_exists('is_active', $data) && ! $data['is_active']);
        abort_if($demoted && $this->isLastManager($member), 422, 'A factory needs at least one active manager. Add another manager first.');
        $phone = Arr::pull($data, 'phone');
        $member = DB::transaction(fn () => $this->members->update($member, $data, $phone));

        return response()->json([
            'status' => true,
            'data' => $this->members->list($uuid)->firstWhere('uuid', $member->uuid),
        ]);
    }

    /**
     * DELETE /api/admin/dairy/factories/{uuid}/members/{memberUuid}
     * Removes the person from the factory; their app account is kept.
     */
    public function destroy(Request $request, string $uuid, string $memberUuid): JsonResponse
    {
        $this->factory($request, $uuid);
        $member = FactoryOperator::query()->where('factory_uuid', $uuid)->where('uuid', $memberUuid)->firstOrFail();
        abort_if($this->isLastManager($member), 422, 'A factory needs at least one active manager. Add another manager first.');
        $member->delete();

        return response()->json(['status' => true, 'message' => 'Member removed from the factory.']);
    }

    private function isLastManager(FactoryOperator $member): bool
    {
        return $member->role === 'manager' && $member->is_active && ! FactoryOperator::query()
            ->where('factory_uuid', $member->factory_uuid)->where('role', 'manager')->where('is_active', true)
            ->whereKeyNot($member->getKey())->exists();
    }

    private function factory(Request $request, string $uuid): object
    {
        abort_unless($request->user()->platform_role === 'superAdmin', 403);
        $factory = DB::table('dairy_factories')->where('uuid', $uuid)->first();
        abort_unless($factory, 404, 'Factory not found.');

        return $factory;
    }
}
