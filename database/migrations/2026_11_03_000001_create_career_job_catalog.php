<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_job_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name_en');
            $table->string('name_sw');
            $table->unsignedInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // These are controlled form choices. Keeping them in one catalogue lets
        // new values be added without changing the mobile application.
        Schema::create('career_job_options', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 32);
            $table->string('code', 100);
            $table->string('name_en');
            $table->string('name_sw');
            $table->unsignedInteger('sort_order');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['group', 'code']);
        });

        Schema::table('career_jobs', function (Blueprint $table): void {
            $table->foreignId('job_type_id')->nullable()->after('owner_user_id')->constrained('career_job_types')->restrictOnDelete();
            $table->index(['job_type_id', 'closed', 'closes_at']);
        });

        $now = now();
        $types = [
            ['dairy', 'Dairy & Milking', 'Maziwa na Ukamuaji'],
            ['herd_management', 'Herd Management', 'Usimamizi wa Mifugo'],
            ['veterinary', 'Veterinary & Animal Health', 'Utabibu na Afya ya Wanyama'],
            ['feed_nutrition', 'Feed & Nutrition', 'Chakula na Lishe ya Mifugo'],
            ['farm_machinery', 'Farm Machinery', 'Mashine za Shamba'],
            ['farm_management', 'Farm Management', 'Usimamizi wa Shamba'],
            ['breeding', 'Breeding & Insemination', 'Uzazi na Uhimilishaji'],
            ['sales', 'Livestock Sales', 'Mauzo ya Mifugo'],
            ['other', 'Other', 'Nyingine'],
        ];
        DB::table('career_job_types')->insert(array_map(
            fn ($entry, $index) => ['code' => $entry[0], 'name_en' => $entry[1], 'name_sw' => $entry[2], 'sort_order' => $index, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            $types,
            array_keys($types)
        ));

        $options = [
            'jobNature' => [
                ['Full Time', 'Full Time', 'Muda Wote'],
                ['Part Time', 'Part Time', 'Muda Sehemu'],
                ['Contract', 'Contract', 'Mkataba'],
                ['Seasonal', 'Seasonal', 'Msimu'],
            ],
            'workingModel' => [
                ['On-Site', 'On-Site', 'Eneo la Kazi'],
                ['On-Site Farm', 'On-Site Farm', 'Shambani'],
                ['Day Shift', 'Day Shift', 'Zamu ya Mchana'],
                ['Hybrid / Field', 'Hybrid / Field', 'Mchanganyiko / Nje'],
                ['Remote/Consulting', 'Remote / Consulting', 'Kwa Mbali / Ushauri'],
            ],
            'experienceLevel' => [
                ['Entry Level', 'Entry Level', 'Ngazi ya Kuanza'],
                ['1-3 Years', '1–3 Years', 'Miaka 1–3'],
                ['3-5 Years', '3–5 Years', 'Miaka 3–5'],
                ['5+ Years', '5+ Years', 'Miaka 5+'],
            ],
        ];
        foreach ($options as $group => $items) {
            DB::table('career_job_options')->insert(array_map(
                fn ($entry, $index) => ['group' => $group, 'code' => $entry[0], 'name_en' => $entry[1], 'name_sw' => $entry[2], 'sort_order' => $index, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                $items,
                array_keys($items)
            ));
        }
    }

    public function down(): void
    {
        Schema::table('career_jobs', function (Blueprint $table): void {
            $table->dropForeign(['job_type_id']);
            $table->dropIndex(['job_type_id', 'closed', 'closes_at']);
            $table->dropColumn('job_type_id');
        });
        Schema::dropIfExists('career_job_options');
        Schema::dropIfExists('career_job_types');
    }
};
