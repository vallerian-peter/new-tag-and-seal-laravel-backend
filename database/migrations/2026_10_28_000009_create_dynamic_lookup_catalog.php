<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_sets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 120);
            $table->unsignedInteger('version')->default(1);
            $table->string('name');
            $table->json('translations_json')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_extensible')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['code', 'version']);
        });

        Schema::create('lookup_options', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('lookup_set_uuid')->index();
            $table->string('code', 120);
            $table->string('label');
            $table->json('translations_json')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['lookup_set_uuid', 'code']);
        });

        $catalog = [
            'definition_status' => ['Definition status', ['draft', 'published', 'retired']],
            'lifecycle_status' => ['Animal lifecycle status', ['active', 'inactive', 'deceased', 'sold', 'transferred', 'merged']],
            'record_status' => ['Event record status', ['draft', 'submitted', 'confirmed', 'voided']],
            'identifier_status' => ['Identifier status', ['active', 'replaced', 'lost', 'retired']],
        ];
        foreach ($catalog as $code => [$name, $options]) {
            $setUuid = (string) Str::uuid();
            DB::table('lookup_sets')->insert([
                'uuid' => $setUuid,
                'code' => $code,
                'version' => 1,
                'name' => $name,
                'status' => 'published',
                'is_system' => true,
                'is_extensible' => false,
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($options as $order => $option) {
                DB::table('lookup_options')->insert([
                    'uuid' => (string) Str::uuid(),
                    'lookup_set_uuid' => $setUuid,
                    'code' => $option,
                    'label' => str($option)->replace('_', ' ')->title(),
                    'sort_order' => $order,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_options');
        Schema::dropIfExists('lookup_sets');
    }
};
