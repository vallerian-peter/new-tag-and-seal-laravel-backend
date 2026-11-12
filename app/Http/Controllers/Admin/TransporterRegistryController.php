<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesTransporters;
use App\Http\Controllers\Controller;
use App\Models\TransporterCompany;
use App\Services\Dairy\TransporterNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Portal registry of transporter companies.
 * A super admin (or anyone with the dairy permission) sees every company;
 * an MCU admin sees and decides only the companies registered under their MCU.
 */
class TransporterRegistryController extends Controller
{
    use ScopesTransporters;

    public function __construct(private readonly TransporterNotifications $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $mcus = $this->scope($request);
        $data = $request->validate([
            'search' => 'nullable|string|max:120',
            'tab' => ['nullable', Rule::in(['all', 'pending', 'approved', 'rejected', 'archived'])],
            'sort' => ['nullable', Rule::in(['desc', 'asc', 'alpha-asc', 'alpha-desc'])],
            'per_page' => 'nullable|integer|min:10|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $query = $this->companies($mcus)
            ->leftJoin('cooperative_mcus as mcu', 'mcu.uuid', '=', 'c.mcu_uuid')
            ->leftJoin('regions as region', 'region.id', '=', 'c.region_id')
            ->leftJoin('districts as district', 'district.id', '=', 'c.district_id')
            ->select('c.*', 'mcu.name as mcu_name', 'region.name as region_name', 'district.name as district_name')
            ->selectSub(fn ($q) => $q->from('transporter_vehicles')->whereColumn('transporter_company_uuid', 'c.uuid')->selectRaw('count(*)'), 'vehicles_count')
            ->selectSub(fn ($q) => $q->from('transporter_drivers')->whereColumn('transporter_company_uuid', 'c.uuid')->selectRaw('count(*)'), 'drivers_count');
        $tab = $data['tab'] ?? 'all';
        $tab === 'archived' ? $query->whereNotNull('c.archived_at') : $query->whereNull('c.archived_at');
        if (in_array($tab, ['pending', 'approved', 'rejected'], true)) {
            $query->where('c.approval_status', $tab);
        }
        if (! empty($data['search'])) {
            $term = '%'.trim($data['search']).'%';
            $query->where(fn ($q) => $q->where('c.name', 'like', $term)
                ->orWhere('c.license_number', 'like', $term)
                ->orWhere('c.tin_number', 'like', $term)
                ->orWhere('c.contact_person', 'like', $term)
                ->orWhere('c.phone', 'like', $term)
                ->orWhere('mcu.name', 'like', $term));
        }
        [$sort, $direction] = match ($data['sort'] ?? 'desc') {
            'asc' => ['c.created_at', 'asc'],
            'alpha-asc' => ['c.name', 'asc'],
            'alpha-desc' => ['c.name', 'desc'],
            default => ['c.created_at', 'desc'],
        };
        $page = $query->orderBy($sort, $direction)->orderBy('c.id')->paginate($data['per_page'] ?? 10);
        $live = $this->companies($mcus)->whereNull('c.archived_at');
        $uuids = (clone $live)->pluck('c.uuid');

        return response()->json([
            'status' => true,
            'data' => $page->items(),
            'meta' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'stats' => [
                    'total' => (clone $live)->count(),
                    'pending' => (clone $live)->where('c.approval_status', 'pending')->count(),
                    'approved' => (clone $live)->where('c.approval_status', 'approved')->count(),
                    'rejected' => (clone $live)->where('c.approval_status', 'rejected')->count(),
                    'vehicles' => DB::table('transporter_vehicles')->whereIn('transporter_company_uuid', $uuids)->count(),
                    'drivers' => DB::table('transporter_drivers')->whereIn('transporter_company_uuid', $uuids)->count(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $company = $this->find($request, $uuid);

        return response()->json(['status' => true, 'data' => [
            ...$company->toArray(),
            'mcu_name' => DB::table('cooperative_mcus')->where('uuid', $company->mcu_uuid)->value('name'),
            'vehicles' => DB::table('transporter_vehicles')->where('transporter_company_uuid', $company->uuid)
                ->orderBy('registration_number')->get(),
            'drivers' => DB::table('transporter_drivers')->where('transporter_company_uuid', $company->uuid)
                ->orderBy('first_name')->get(),
        ]]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $company = $this->find($request, $uuid);
        if ($request->has('tin_number')) {
            $request->merge(['tin_number' => TransporterCompany::normaliseTin($request->input('tin_number'))]);
        }
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'mcu_uuid' => ['sometimes', 'required', 'string', Rule::exists('cooperative_mcus', 'uuid')],
            'license_number' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('transporter_companies', 'license_number')->ignore($company->id)],
            'tin_number' => ['sometimes', 'required', 'string', 'regex:'.TransporterCompany::TIN_PATTERN],
            'phone' => 'sometimes|required|string|max:50',
            'email' => 'nullable|email|max:255',
            'contact_person' => 'sometimes|required|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'contact_title' => 'nullable|string|max:120',
            'physical_address' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
        ]);
        if (isset($data['mcu_uuid']) && ($mcus = $this->scope($request)) !== null) {
            abort_unless(in_array($data['mcu_uuid'], $mcus, true), 403);
        }
        $company->update($data);

        return response()->json(['status' => true, 'data' => $company->fresh()]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $company = $this->find($request, $uuid);
        $company->update(['archived_at' => now(), 'is_active' => false]);

        return response()->json(['status' => true, 'message' => 'Transporter archived.']);
    }

    public function restore(Request $request, string $uuid): JsonResponse
    {
        $company = $this->find($request, $uuid);
        $company->update(['archived_at' => null, 'is_active' => $company->approval_status === 'approved']);

        return response()->json(['status' => true, 'data' => $company->fresh()]);
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        return $this->decide($request, $uuid, 'approved');
    }

    public function reject(Request $request, string $uuid): JsonResponse
    {
        return $this->decide($request, $uuid, 'rejected');
    }

    private function decide(Request $request, string $uuid, string $decision): JsonResponse
    {
        $company = $this->find($request, $uuid);
        $data = $request->validate([
            'remarks' => [$decision === 'rejected' ? 'required' : 'nullable', 'string', 'max:500'],
        ]);
        abort_unless($company->approval_status === 'pending', 409, 'This registration has already been decided. Refresh and try again.');
        $company->update($decision === 'approved' ? [
            'approval_status' => 'approved',
            'approved_by_user_id' => $request->user()->id,
            'approval_remarks' => $data['remarks'] ?? null,
            'approved_at' => now(),
            'is_active' => true,
        ] : [
            'approval_status' => 'rejected',
            'approved_by_user_id' => $request->user()->id,
            'approval_remarks' => $data['remarks'],
            'rejected_at' => now(),
            'is_active' => false,
        ]);
        $this->notifications->decided($company->fresh(), $decision);

        return response()->json(['status' => true, 'data' => $company->fresh()]);
    }
}
