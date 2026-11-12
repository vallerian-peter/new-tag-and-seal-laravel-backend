<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplacePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_listing_photo_is_available_to_buyers(): void
    {
        Storage::fake('local');
        $seller = User::factory()->create(['platform_role' => 'superAdmin']);
        $buyer = User::factory()->create();
        $type = DB::table('livestock_types')->insertGetId(['name' => 'Cattle']);
        $breed = DB::table('breeds')->insertGetId([
            'name' => 'Friesian', 'group' => 'Dairy', 'livestockTypeId' => $type,
        ]);
        $species = DB::table('species')->insertGetId(['name' => 'Bos taurus']);
        DB::table('livestocks')->insert([
            'farmUuid' => 'farm-1', 'uuid' => 'animal-1', 'identificationNumber' => 'TAG-1',
            'livestockTypeId' => $type, 'name' => 'Daisy', 'dateOfBirth' => '2020-01-01',
            'gender' => 'female', 'status' => 'active', 'breedId' => $breed, 'speciesId' => $species,
        ]);
        $response = $this->actingAs($seller)->postJson('/api/v2/marketplace/listings', [
            'listing_title' => 'Daisy', 'listing_type' => 'individual',
            'animal_uuids' => ['animal-1'], 'unit_price' => 100,
            'photo_files' => [UploadedFile::fake()->image('daisy.jpg')],
        ])->assertCreated();
        $photo = $response->json('data.photos.0');
        Storage::disk('local')->assertExists('marketplace-photos/'.$photo);
        $this->actingAs($buyer)->getJson('/api/v2/marketplace/listings')
            ->assertOk()->assertJsonPath('data.0.photos.0', $photo);
        $this->get('/api/v2/marketplace/photos/'.$photo)->assertOk();
        $this->get('/api/v2/marketplace/photos/not-a-photo.jpg')->assertNotFound();
    }

    public function test_invalid_photo_is_rejected(): void
    {
        Storage::fake('local');
        $seller = User::factory()->create(['platform_role' => 'superAdmin']);
        $this->actingAs($seller)->postJson('/api/v2/marketplace/listings', [
            'listing_title' => 'Daisy', 'listing_type' => 'individual',
            'animal_uuids' => ['animal-1'], 'unit_price' => 100,
            'photo_files' => [UploadedFile::fake()->create('file.txt', 1, 'text/plain')],
        ])->assertUnprocessable();
        $this->assertSame([], Storage::disk('local')->allFiles('marketplace-photos'));
    }
}
