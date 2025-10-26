<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create system user
        User::create([
            'username' => 'saleh.salim@gmail.com',
            'password' => Hash::make('password123'),
            'profile' => 'SystemUser',
            'profile_id' => 1,
            'status_id' => 1,
            'created_by' => 1,
            'state_id' => 1,
            'remember_token' => '7nSDELeqR0du4RPW7q0BtELWnul2m53puvngWnl2LkrnE9W9cZ',
            'created_at' => '2024-05-18 10:30:08',
            'updated_at' => '2025-09-24 16:56:01',
        ]);

        // Create farmer users
        User::create([
            'username' => 'tecyfe@mailinator.com',
            'password' => Hash::make('password123'),
            'profile' => 'Farmer',
            'profile_id' => 1,
            'status_id' => 1,
            'created_by' => 1,
            'created_at' => '2024-05-18 10:34:50',
            'updated_at' => '2024-05-18 10:34:50',
        ]);

        User::create([
            'username' => 'zijytefap@mailinator.com',
            'password' => Hash::make('password123'),
            'profile' => 'Farmer',
            'profile_id' => 2,
            'status_id' => 1,
            'created_by' => 1,
            'created_at' => '2024-05-18 10:35:16',
            'updated_at' => '2024-05-18 10:35:16',
        ]);
    }
}
