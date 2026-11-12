<?php

namespace App\Services\Dairy;

use App\Models\TransporterCompany;
use App\Models\TransporterDriver;
use App\Models\TransporterVehicle;
use App\Models\User;
use App\Services\PhoneAuthentication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Vehicle and driver writes shared by the transporter app and the admin portal,
 * so a driver added from either gets the same account waiting for a first PIN.
 */
class TransporterFleetService
{
    public function __construct(private readonly PhoneAuthentication $phones) {}

    public static function vehicleRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'registration_number' => [$required, 'string', 'max:50'],
            'vehicle_type' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:40'],
            'tare_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'tanker_capacity_liters' => [$required, 'numeric', 'gt:0'],
        ];
    }

    public static function driverRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'first_name' => [$required, 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => [$required, 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'licence_number' => [$required, 'string', 'max:50'],
            'licence_class' => [$required, Rule::in(['A', 'B', 'C', 'D', 'E'])],
            'licence_expiry' => ['nullable', 'date'],
            'national_id' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** Creates or updates a vehicle; a plate is unique across all transporters. */
    public function saveVehicle(Request $request, TransporterCompany $company, string $uuid, array $data): array
    {
        $vehicle = TransporterVehicle::query()->where('uuid', $uuid)->first();
        if ($vehicle && $vehicle->transporter_company_uuid !== $company->uuid) {
            abort(409, $this->say($request, 'This vehicle belongs to another transporter.', 'Gari hili ni la msafirishaji mwingine.'));
        }
        if (isset($data['registration_number'])) {
            $plate = strtoupper(preg_replace('/\s+/', '', $data['registration_number']));
            $taken = TransporterVehicle::query()->where('registration_number', $plate)->where('uuid', '!=', $uuid)->exists();
            abort_if($taken, 409, $this->say($request,
                "Plate {$plate} is already registered.", "Namba ya gari {$plate} tayari imesajiliwa."));
            $data['registration_number'] = $plate;
        }

        $vehicle ??= new TransporterVehicle([
            'uuid' => $uuid,
            'transporter_company_uuid' => $company->uuid,
            'vehicle_type' => 'TANKER',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);
        $vehicle->fill(collect($data)->only(array_keys(self::vehicleRules()))->all());
        $vehicle->status ??= 'available';
        $created = ! $vehicle->exists;
        $vehicle->save();

        return [$vehicle->fresh(), $created];
    }

    /** New driver with a phone account that sets its own PIN on first login. */
    public function createDriver(Request $request, TransporterCompany $company, string $uuid, array $data): TransporterDriver
    {
        $existing = TransporterDriver::query()->where('uuid', $uuid)->first();
        if ($existing) {
            abort_unless($existing->transporter_company_uuid === $company->uuid, 409,
                $this->say($request, 'This driver belongs to another transporter.', 'Dereva huyu ni wa msafirishaji mwingine.'));

            return $existing;
        }
        $phone = $this->phones->normalize((string) ($data['phone'] ?? ''));
        abort_unless($phone, 422, $this->say($request, 'The phone number is invalid.', 'Namba ya simu si sahihi.'));
        if ($this->phones->matchingUserIds($phone) !== []
            || TransporterDriver::query()->where('phone', $phone)->exists()) {
            abort(409, $this->say($request,
                "{$data['phone']} is already used by another account. Use a different phone number.",
                "Namba {$data['phone']} tayari inatumiwa na akaunti nyingine. Tumia namba nyingine."));
        }

        $id = (string) Str::uuid();
        $user = new User;
        $user->forceFill([
            'username' => 'driver-'.$id,
            'email' => $id.'@accounts.invalid',
            'password' => Str::random(64),
            'role' => 'appUser',
            'roleId' => 0,
            'status' => 'active',
        ])->save();
        DB::table('phone_identities')->insert([
            'user_id' => $user->id,
            'phone' => $phone,
            'display_name' => TransporterDriver::fullName($data),
            'pin_hash' => Hash::make(Str::random(12)),
            'pin_pending' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return TransporterDriver::create([
            'uuid' => $uuid,
            'transporter_company_uuid' => $company->uuid,
            'user_id' => $user->id,
            'driver_license_number' => $data['licence_number'],
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'phone' => $phone,
            'email' => $data['email'] ?? null,
            'licence_class' => $data['licence_class'],
            'licence_expiry' => $data['licence_expiry'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'approval_status' => 'approved',
            'status' => 'standby',
            'is_active' => true,
        ]);
    }

    /** Details only; the phone stays because it is the driver's login. */
    public function updateDriver(TransporterDriver $driver, array $data): TransporterDriver
    {
        if (array_key_exists('licence_number', $data)) {
            $data['driver_license_number'] = $data['licence_number'];
        }
        $driver->update(collect($data)->only([
            'first_name', 'middle_name', 'last_name', 'email', 'driver_license_number', 'licence_class', 'licence_expiry', 'national_id',
        ])->all());
        if (array_intersect(['first_name', 'middle_name', 'last_name'], array_keys($data))) {
            DB::table('phone_identities')->where('user_id', $driver->user_id)->where('pin_pending', true)
                ->update(['display_name' => TransporterDriver::fullName($driver->getAttributes()), 'updated_at' => now()]);
        }

        return $driver->fresh();
    }

    /** One driver per vehicle: assigning moves any other driver off it. */
    public function assign(string $companyUuid, string $driverUuid, ?string $vehicleUuid): void
    {
        $driver = TransporterDriver::query()->where('uuid', $driverUuid)
            ->where('transporter_company_uuid', $companyUuid)->firstOrFail();
        if ($vehicleUuid) {
            TransporterVehicle::query()->where('uuid', $vehicleUuid)
                ->where('transporter_company_uuid', $companyUuid)->firstOrFail();
            TransporterDriver::query()->where('assigned_vehicle_uuid', $vehicleUuid)
                ->where('uuid', '!=', $driverUuid)
                ->update(['assigned_vehicle_uuid' => null, 'status' => 'standby']);
        }
        $driver->update([
            'assigned_vehicle_uuid' => $vehicleUuid,
            'status' => $vehicleUuid ? 'active' : 'standby',
        ]);
    }

    /** Archive keeps history; the vehicle loses its driver, the driver its vehicle. */
    public function setVehicleActive(TransporterVehicle $vehicle, bool $active): void
    {
        $vehicle->update(['is_active' => $active]);
        if (! $active) {
            TransporterDriver::query()->where('assigned_vehicle_uuid', $vehicle->uuid)
                ->update(['assigned_vehicle_uuid' => null, 'status' => 'standby']);
        }
    }

    public function setDriverActive(TransporterDriver $driver, bool $active): void
    {
        $driver->update($active ? ['is_active' => true] : ['is_active' => false, 'assigned_vehicle_uuid' => null, 'status' => 'standby']);
    }

    public function say(Request $request, string $english, string $swahili): string
    {
        return str_starts_with(strtolower((string) $request->header('Accept-Language', 'en')), 'sw')
            ? $swahili
            : $english;
    }
}
