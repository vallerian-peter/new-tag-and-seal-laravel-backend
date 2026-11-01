<?php

namespace Tests\Feature\Dynamic;

use App\Enums\EventBehavior;
use App\Enums\UserRole;
use App\Models\AnimalBlueprint;
use App\Models\AnimalSubject;
use App\Models\SubjectIdentifier;
use App\Models\User;
use App\Services\Dynamic\EventBehaviorDispatcher;
use App\Services\Upgrade\DynamicV1Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GroupAndIdentifierBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_split_and_paired_identifier_behaviors_preserve_invariants(): void
    {
        $actor = User::factory()->create(['role' => UserRole::SYSTEM_USER]);
        app(DynamicV1Installer::class)->install($actor->uuid);
        $blueprint = AnimalBlueprint::query()->where('code', 'chicken.standard')->firstOrFail();
        $subject = $this->subject($actor, 'group', $blueprint, 10);
        $newUuid = (string) Str::uuid();
        $dispatcher = app(EventBehaviorDispatcher::class);

        $dispatcher->apply(EventBehavior::SPLIT_GROUP, $subject, [
            'split_quantity' => 4,
            'new_group_uuid' => $newUuid,
            'new_group_name' => 'House B',
        ], $actor);
        $subject->save();
        $this->assertSame(6, $subject->fresh()->quantity);
        $split = AnimalSubject::query()->where('uuid', $newUuid)->firstOrFail();
        $this->assertSame(4, $split->quantity);

        $dispatcher->apply(EventBehavior::ASSIGN_IDENTIFIER, $split, [
            'identifier_type_code' => 'paired_rfid_barcode',
            'identifier_value' => ' tag-900 ',
        ], $actor);
        $identifier = SubjectIdentifier::query()->where('subject_uuid', $split->uuid)->firstOrFail();
        $this->assertSame('TAG-900', $identifier->normalized_value);
        $this->assertSame('tag-900', $identifier->display_value);
    }

    private function subject(User $actor, string $unitType, ?AnimalBlueprint $blueprint = null, ?int $quantity = null): AnimalSubject
    {
        return AnimalSubject::create([
            'farm_uuid' => (string) Str::uuid(),
            'species_uuid' => $blueprint?->species_uuid ?? (string) Str::uuid(),
            'blueprint_uuid' => $blueprint?->uuid ?? (string) Str::uuid(),
            'blueprint_version' => $blueprint?->version ?? 1,
            'unit_type' => $unitType,
            'display_name' => 'Test subject',
            'sex_code' => $unitType === 'group' ? 'not_applicable' : 'female',
            'quantity' => $quantity,
            'quantity_unit' => $quantity === null ? null : 'animals',
            'lifecycle_status' => 'active',
            'attributes_json' => [],
            'revision' => 1,
            'created_by_uuid' => $actor->uuid,
            'updated_by_uuid' => $actor->uuid,
        ]);
    }
}
