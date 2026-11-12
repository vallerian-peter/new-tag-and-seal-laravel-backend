<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSpecies;
use App\Models\AnimalSubject;
use App\Models\Country;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\SystemUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventsAndFinanceCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Farm $farm;

    private Farmer $farmer;

    private AnimalSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $country = Country::create([
            'name' => 'Tanzania',
            'shortName' => 'TZ',
        ]);

        $systemProfile = SystemUser::create([
            'firstName' => 'Events',
            'lastName' => 'Admin',
            'phone' => '+255700000444',
            'address' => 'Dodoma HQ',
            'status' => 'active',
        ]);

        $this->admin = User::create([
            'username' => 'events.admin',
            'email' => 'events.admin@tagandseal.co.tz',
            'password' => Hash::make('password123'),
            'role' => UserRole::SYSTEM_USER,
            'roleId' => $systemProfile->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->farmer = Farmer::create([
            'farmerNo' => 'FRM-EVT-001',
            'firstName' => 'Baraka',
            'surname' => 'Msuya',
            'phone1' => '+255712000111',
            'gender' => 'male',
            'farmerType' => 'individual',
            'countryId' => $country->id,
            'createdBy' => $this->admin->id,
            'status' => 'active',
        ]);

        $this->farm = Farm::create([
            'farmerId' => $this->farmer->id,
            'uuid' => (string) Str::uuid(),
            'referenceNo' => 'REF-EVT-001',
            'regionalRegNo' => 'REG-EVT-001',
            'name' => 'Kilimanjaro Highland Farm',
            'size' => '18',
            'sizeUnit' => 'acre',
            'latitudes' => '-3.3869',
            'longitudes' => '36.6830',
            'physicalAddress' => 'Moshi Rural',
            'countryId' => $country->id,
            'status' => 'active',
        ]);

        $species = AnimalSpecies::create([
            'uuid' => (string) Str::uuid(),
            'code' => 'cattle',
            'name' => 'Cattle',
            'default_subject_mode' => 'individual',
            'status' => 'published',
        ]);

        $this->admin->forceFill(['platform_role' => 'superAdmin'])->save();
        Sanctum::actingAs($this->admin);

        $animalResponse = $this->postJson('/api/v1/admin/animals/animals', [
            'display_name' => 'Malkia',
            'primary_tag' => 'TZ-COW-8801',
            'farm_uuid' => $this->farm->uuid,
            'species_uuid' => $species->uuid,
            'unit_type' => 'individual',
            'sex_code' => 'female',
            'quantity' => 1,
            'lifecycle_status' => 'active',
        ])->assertCreated();

        $this->subject = AnimalSubject::findOrFail($animalResponse->json('data.id'));
    }

    public function test_event_resources_support_crud_archive_restore_and_force_delete(): void
    {
        // Create a Milking event
        $createResponse = $this->postJson('/api/v1/admin/events/milkings', [
            'farmUuid' => $this->farm->uuid,
            'subjectUuid' => $this->subject->uuid,
            'occurredAt' => '2026-10-28',
            'milkQuantity' => 16.5,
            'session' => 'Morning',
            'milkingMethod' => 'Hand Milking',
            'lactometerReading' => 29.2,
            'totalSolids' => 12.8,
            'protein' => 3.5,
            'remarks' => 'Healthy morning yield',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.subjectIdentifier', 'TZ-COW-8801')
            ->assertJsonPath('data.farmName', 'Kilimanjaro Highland Farm')
            ->assertJsonPath('data.farmerName', 'Baraka Msuya')
            ->assertJsonPath('data.milkQuantity', 16.5)
            ->assertJsonPath('data.session', 'Morning');

        $eventId = $createResponse->json('data.id');

        // Verify it appears in both milkings and all-events
        $this->getJson('/api/v1/admin/events/milkings')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/v1/admin/events/all-events')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);

        // Update payload
        $this->patchJson("/api/v1/admin/events/milkings/{$eventId}", [
            'milkQuantity' => 18.0,
            'session' => 'Evening',
        ])
            ->assertOk()
            ->assertJsonPath('data.milkQuantity', 18)
            ->assertJsonPath('data.session', 'Evening')
            ->assertJsonPath('data.revision', 2);

        // Soft delete (moves to deleted tab / voided status)
        $this->deleteJson("/api/v1/admin/events/milkings/{$eventId}")
            ->assertOk();

        $this->getJson('/api/v1/admin/events/milkings?tab=all')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0)
            ->assertJsonPath('data.meta.deletedCount', 1);

        $this->getJson('/api/v1/admin/events/milkings?tab=deleted')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);

        // Restore from deleted tab
        $this->postJson("/api/v1/admin/events/milkings/{$eventId}/restore")
            ->assertOk()
            ->assertJsonPath('data.recordStatus', 'confirmed');

        // Permanent delete
        $this->deleteJson("/api/v1/admin/events/milkings/{$eventId}?force=1")
            ->assertOk();

        $this->getJson('/api/v1/admin/events/milkings?tab=all')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0)
            ->assertJsonPath('data.meta.deletedCount', 0);
    }

    public function test_identification_events_crud(): void
    {
        $created = $this->postJson('/api/v1/admin/events/identification-events', [
            'farmUuid' => $this->farm->uuid,
            'identificationNumber' => 'TZ-TAG-99401',
            'eventDate' => '2026-10-29',
            'remarks' => 'Initial ear tag applied',
        ]);

        $created->assertCreated()
            ->assertJsonPath('data.identificationNumber', 'TZ-TAG-99401')
            ->assertJsonPath('data.farmName', 'Kilimanjaro Highland Farm');

        $id = $created->json('data.id');

        $this->patchJson("/api/v1/admin/events/identification-events/{$id}", [
            'farmUuid' => $this->farm->uuid,
            'identificationNumber' => 'TZ-TAG-99402',
            'remarks' => 'Replaced damaged tag',
        ])
            ->assertOk()
            ->assertJsonPath('data.identificationNumber', 'TZ-TAG-99402');

        $this->deleteJson("/api/v1/admin/events/identification-events/{$id}")
            ->assertOk();
    }

    public function test_all_finance_dashboards_and_bill_search_use_officer_last_name(): void
    {
        $db = \Illuminate\Support\Facades\DB::class;
        $region = $db::table('regions')->insertGetId(['name' => 'Region', 'shortName' => 'RG', 'countryId' => $this->farm->countryId]);
        $district = $db::table('districts')->insertGetId(['name' => 'District', 'regionId' => $region]);
        $ward = $db::table('wards')->insertGetId(['name' => 'Ward', 'districtId' => $district]);
        $officer = $db::table('extension_officers')->insertGetId([
            'firstName' => 'Asha', 'middleName' => 'Jane', 'lastName' => 'UniqueOfficer',
            'email' => 'finance-officer@example.test', 'phone' => '+255712345678',
            'password' => 'unused', 'gender' => 'female', 'countryId' => $this->farm->countryId,
            'regionId' => $region, 'districtId' => $district, 'wardId' => $ward,
        ]);
        $bill = $this->postJson('/api/v1/admin/finance/bills', [
            'farmUuid' => $this->farm->uuid, 'extensionOfficerId' => $officer,
            'subjectType' => 'Vaccination', 'quantity' => 1, 'amount' => 1000, 'status' => 'pending',
        ])->assertCreated()->assertJsonPath('data.extensionOfficerName', 'Asha Jane UniqueOfficer');

        foreach (['bills', 'income', 'expenditure', 'income-expenditure'] as $resource) {
            $this->getJson('/api/v1/admin/finance/'.$resource)->assertOk()
                ->assertJsonPath('data.options.extensionOfficers.0.label', 'Asha Jane UniqueOfficer');
            $this->getJson('/api/v1/admin/finance/'.$resource.'/options')->assertOk()
                ->assertJsonPath('data.extensionOfficers.0.label', 'Asha Jane UniqueOfficer');
        }
        $this->getJson('/api/v1/admin/finance/bills?search=UniqueOfficer')->assertOk()
            ->assertJsonPath('data.meta.total', 1)->assertJsonPath('data.items.0.id', $bill->json('data.id'));
        $this->getJson('/api/v1/admin/finance/income-expenditure')->assertOk()
            ->assertJsonPath('data.billRows.0.officer', 'Asha Jane UniqueOfficer');
    }

    public function test_finance_bills_income_expenditure_and_report(): void
    {
        // 1. Create a Bill (auto-generates billNo and auto-fills farmerId from farm)
        $billResponse = $this->postJson('/api/v1/admin/finance/bills', [
            'farmUuid' => $this->farm->uuid,
            'subjectType' => 'Vaccination',
            'quantity' => 5,
            'amount' => 75000,
            'status' => 'pending',
            'notes' => 'ECF vaccination batch',
        ]);

        $billResponse->assertCreated()
            ->assertJsonPath('data.farmName', 'Kilimanjaro Highland Farm')
            ->assertJsonPath('data.farmerName', 'Baraka Msuya')
            ->assertJsonPath('data.amount', 75000)
            ->assertJsonPath('data.status', 'pending');

        $billId = $billResponse->json('data.id');

        // Mark Bill as Paid
        $this->patchJson("/api/v1/admin/finance/bills/{$billId}", [
            'markPaid' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        // 2. Create Income (auto-computes totalAmount = quantity * unitAmount)
        $incomeResponse = $this->postJson('/api/v1/admin/finance/income', [
            'sourceType' => 'milk_sale',
            'farmUuid' => $this->farm->uuid,
            'referenceNo' => 'MILK-OCT-01',
            'subjectType' => 'Raw Milk',
            'quantity' => 100,
            'unitAmount' => 1500,
            'status' => 'received',
            'incomeDate' => now()->toDateString(),
        ]);

        $incomeResponse->assertCreated()
            ->assertJsonPath('data.totalAmount', 150000)
            ->assertJsonPath('data.farmName', 'Kilimanjaro Highland Farm');

        // 3. Create Expenditure (auto-computes totalCost = quantity * unitCost)
        $expenseResponse = $this->postJson('/api/v1/admin/finance/expenditure', [
            'sourceType' => 'feed',
            'farmUuid' => $this->farm->uuid,
            'billNo' => 'FEED-INV-09',
            'subjectType' => 'Dairy Meal',
            'quantity' => 2,
            'unitCost' => 45000,
            'status' => 'paid',
            'expenseDate' => now()->toDateString(),
        ]);

        $expenseResponse->assertCreated()
            ->assertJsonPath('data.totalCost', 90000);

        // 4. Verify Income & Expenditure Report aggregates Income (150,000) and Expenditure (90,000 + 75,000 orphan bill = 165,000)
        $reportResponse = $this->getJson('/api/v1/admin/finance/income-expenditure');
        $reportResponse->assertOk()
            ->assertJsonPath('data.summary.totalIncome', 150000)
            ->assertJsonPath('data.summary.totalExpenditure', 165000)
            ->assertJsonPath('data.summary.net', -15000)
            ->assertJsonPath('data.summary.totalIncomeRows', 1)
            ->assertJsonPath('data.summary.totalExpenseRows', 2)
            ->assertJsonPath('data.summary.totalBillRows', 1);
    }
}
