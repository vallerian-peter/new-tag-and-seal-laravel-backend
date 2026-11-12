<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'transporter_companies' => [
            'contact_phone' => ['string', 50],
            'contact_email' => ['string', 255],
            'contact_title' => ['string', 120],
            'region_id' => ['unsignedBigInteger'],
            'district_id' => ['unsignedBigInteger'],
            'physical_address' => ['string', 255],
            'website' => ['string', 255],
            'rejected_at' => ['timestamp'],
            'archived_at' => ['timestamp'],
        ],
        'transporter_vehicles' => [
            'vehicle_type' => ['string', 40],
            'color' => ['string', 40],
            'tare_weight_kg' => ['decimal'],
        ],
        'transporter_drivers' => [
            'first_name' => ['string', 120],
            'last_name' => ['string', 120],
            'phone' => ['string', 50],
            'email' => ['string', 255],
            'licence_class' => ['string', 10],
            'licence_expiry' => ['date'],
            'national_id' => ['string', 40],
        ],
    ];

    public function up(): void
    {
        foreach ($this->columns as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                foreach ($columns as $name => $spec) {
                    if (Schema::hasColumn($tableName, $name)) {
                        continue;
                    }
                    [$type, $length] = [$spec[0], $spec[1] ?? null];
                    match ($type) {
                        'string' => $table->string($name, $length)->nullable(),
                        'decimal' => $table->decimal($name, 10, 2)->nullable(),
                        default => $table->{$type}($name)->nullable(),
                    };
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns) {
                foreach (array_keys($columns) as $name) {
                    if (Schema::hasColumn($tableName, $name)) {
                        $table->dropColumn($name);
                    }
                }
            });
        }
    }
};
