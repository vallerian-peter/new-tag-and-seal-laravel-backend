<?php

namespace Tests\Feature;

use App\Services\FarmerNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FarmerNumberTest extends TestCase
{
    use RefreshDatabase;

    private int $country;

    protected function setUp(): void
    {
        parent::setUp();
        $this->country = DB::table('countries')->insertGetId(['name' => 'Tanzania', 'shortName' => 'TZ']);
    }

    private function farmer(string $farmerNo, string $createdAt): int
    {
        return DB::table('farmers')->insertGetId([
            'farmerNo' => $farmerNo, 'firstName' => 'Asha', 'surname' => 'Juma', 'phone1' => '0712345678',
            'gender' => 'female', 'farmerType' => 'individual', 'countryId' => $this->country,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    public function test_new_numbers_are_four_then_six_random_digits(): void
    {
        $numbers = collect(range(1, 50))->map(fn () => FarmerNumberGenerator::generate());

        $numbers->each(fn ($number) => $this->assertMatchesRegularExpression('/^FMR-[1-9]\d{3}-[1-9]\d{5}$/', $number));
        $this->assertGreaterThan(1, $numbers->map(fn ($number) => substr($number, 4, 4))->unique()->count());
    }

    public function test_renumber_previews_first_and_keeps_valid_numbers(): void
    {
        $legacy = $this->farmer('FMR2025000001', '2025-03-01 10:00:00');
        $short = $this->farmer('F-2026-0009', '2026-11-01 10:00:00');
        $current = $this->farmer('FMR-2026-483920', '2026-11-02 10:00:00');

        $this->artisan('farmers:renumber')->assertSuccessful();
        $this->assertDatabaseHas('farmers', ['id' => $legacy, 'farmerNo' => 'FMR2025000001']);

        $this->artisan('farmers:renumber', ['--apply' => true])->assertSuccessful();
        $numbers = DB::table('farmers')->pluck('farmerNo', 'id');
        $this->assertMatchesRegularExpression(FarmerNumberGenerator::PATTERN, $numbers[$legacy]);
        $this->assertMatchesRegularExpression(FarmerNumberGenerator::PATTERN, $numbers[$short]);
        $this->assertSame('FMR-2026-483920', $numbers[$current]);
    }
}
