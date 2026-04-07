<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin User
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin123'),
            ]
        );

        // Manager User
        User::updateOrCreate(
            ['email' => 'manager@example.com'],
            [
                'name' => 'Manager User',
                'password' => Hash::make('password123'),
            ]
        );

        // Editor User
        User::updateOrCreate(
            ['email' => 'editor@example.com'],
            [
                'name' => 'Editor User',
                'password' => Hash::make('password123'),
            ]
        );

        // Viewer User
        User::updateOrCreate(
            ['email' => 'viewer@example.com'],
            [
                'name' => 'Viewer User',
                'password' => Hash::make('password123'),
            ]
        );
    }
}
