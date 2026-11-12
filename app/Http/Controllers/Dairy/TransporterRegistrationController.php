<?php

namespace App\Http\Controllers\Dairy;

use App\Http\Controllers\Controller;
use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use App\Models\User;
use App\Services\Auth\TransporterMcuOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A transporter company applies under an MCU and waits until an MCU admin or
 * a super admin approves it in the portal. A rejected company can apply again.
 */
class TransporterRegistrationController extends Controller
{
    private const DETAILS = [
        'name', 'mcu_uuid', 'license_number', 'tin_number', 'phone', 'email',
        'contact_person', 'contact_phone', 'contact_email', 'contact_title',
        'region_id', 'district_id', 'physical_address', 'website',
    ];

    public function mine(Request $request): JsonResponse
    {
        $company = DB::table('transporter_companies as c')
            ->leftJoin('cooperative_mcus as m', 'm.uuid', '=', 'c.mcu_uuid')
            ->where('c.manager_user_id', $request->user()->id)
            ->select('c.*', 'm.name as mcu_name')
            ->first();

        return response()->json(['status' => true, 'data' => [
            'mcus' => TransporterMcuOptions::all(),
            'company' => $company,
            'is_driver' => TransporterDriver::query()->where('user_id', $request->user()->id)->exists(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['tin_number' => TransporterCompany::normaliseTin($request->input('tin_number'))]);
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'mcu_uuid' => ['required', 'string', Rule::exists('cooperative_mcus', 'uuid')->where('is_active', true)],
            'license_number' => ['required', 'string', 'max:100'],
            'tin_number' => ['required', 'string', 'regex:'.TransporterCompany::TIN_PATTERN],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_title' => ['nullable', 'string', 'max:120'],
            'region_id' => ['nullable', 'integer'],
            'district_id' => ['nullable', 'integer'],
            'physical_address' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        $data['region_id'] = TransporterMcuOptions::regionFor($data['mcu_uuid'], $data['region_id'] ?? null);
        $data['district_id'] = null;

        return DB::transaction(function () use ($request, $data): JsonResponse {
            $userId = $request->user()->id;
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

            $mine = TransporterCompany::query()->where('manager_user_id', $userId)->first();
            if ($mine && $mine->approval_status === 'rejected') {
                validator($data, [
                    'license_number' => [Rule::unique('transporter_companies', 'license_number')->ignore($mine->id)],
                ])->validate();
                $mine->update([
                    ...collect($data)->except('uuid')->all(),
                    'approval_status' => 'pending',
                    'approval_remarks' => null,
                    'rejected_at' => null,
                    'is_active' => true,
                ]);

                return response()->json(['status' => true, 'data' => $mine->fresh()]);
            }

            $existing = TransporterCompany::query()->where('uuid', $data['uuid'])->first();
            if ($existing) {
                abort_unless($existing->manager_user_id === $userId, 409, $this->say($request,
                    'Registration UUID is already in use.', 'Namba ya usajili tayari inatumika.'));
                foreach (self::DETAILS as $field) {
                    abort_if((string) $existing->{$field} !== (string) ($data[$field] ?? ''), 409, $this->say($request,
                        'Registration UUID was already used for different details.',
                        'Namba ya usajili ilishatumika kwa taarifa tofauti.'));
                }

                return response()->json(['status' => true, 'data' => $existing]);
            }

            abort_if(
                TransporterDriver::query()->where('user_id', $userId)->exists(),
                409,
                $this->say($request,
                    'You are registered as a driver, so you cannot register a transporter company.',
                    'Umesajiliwa kama dereva, hivyo huwezi kusajili kampuni ya usafirishaji.')
            );
            abort_if($mine !== null, 409, $this->say($request,
                'You already have a transporter company registration.',
                'Tayari una usajili wa kampuni ya usafirishaji.'));
            validator($data, [
                'license_number' => [Rule::unique('transporter_companies', 'license_number')],
            ], [
                'license_number.unique' => $this->say($request,
                    'This registration number is already used by another company.',
                    'Namba hii ya usajili inatumiwa na kampuni nyingine.'),
            ])->validate();

            $company = TransporterCompany::create([
                ...$data,
                'contact_phone' => $data['contact_phone'] ?? null,
                'manager_user_id' => $userId,
                'approval_status' => 'pending',
                'is_active' => true,
            ]);

            return response()->json(['status' => true, 'data' => $company], 201);
        });
    }

    private function say(Request $request, string $english, string $swahili): string
    {
        return str_starts_with(strtolower((string) $request->header('Accept-Language', 'en')), 'sw')
            ? $swahili
            : $english;
    }
}
