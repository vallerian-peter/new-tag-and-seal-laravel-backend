<?php

namespace Database\Seeders;

use App\Models\DairyFactory;
use App\Models\District;
use App\Models\TransporterCompany;
use App\Models\TransporterVehicle;
use Illuminate\Database\Seeder;

/**
 * Optional development fixtures for the PDF V2 dairy logistics admin module.
 * This seeder is intentionally not registered in DatabaseSeeder.
 */
class DairyLogisticsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $district = District::query()->first();
        if (! $district) {
            return;
        }

        $transporter = TransporterCompany::firstOrCreate(
            ['license_number' => 'LATRA-DEMO-001'],
            [
                'name' => 'Tag & Seal Demo Transport',
                'contact_person' => 'Demo Dispatcher',
                'phone' => '+255 700 000 000',
                'approval_status' => 'approved',
                'is_active' => true,
            ],
        );

        TransporterVehicle::firstOrCreate(
            ['registration_number' => 'T 000 DEMO'],
            [
                'transporter_company_uuid' => $transporter->uuid,
                'tanker_capacity_liters' => 10000,
                'approval_status' => 'approved',
                'is_active' => true,
            ],
        );

        DairyFactory::firstOrCreate(
            ['factory_code' => 'FAC-DEMO-001'],
            [
                'name' => 'Tag & Seal Demo Dairy Plant',
                'registration_number' => 'BRELA-DEMO-001',
                'contact_name' => 'Demo Plant Manager',
                'contact_phone' => '+255 700 000 001',
                'region_id' => $district->regionId,
                'district_id' => $district->id,
                'approval_status' => 'approved',
                'is_active' => true,
            ],
        );
    }
}
