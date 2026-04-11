<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Auth Service Database Seeder
 *
 * Seeds the same enterprise users as user-service with MATCHING passwords.
 * Auth-service is the source of truth for authentication credentials.
 * These passwords MUST stay in sync with user-service.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'email'    => 'admin@example.com',
                'name'     => 'Super Admin',
                'password' => 'Admin@123456',
            ],
            [
                'email'    => 'orgadmin@example.com',
                'name'     => 'Org Admin',
                'password' => 'OrgAdmin@123',
            ],
            [
                'email'    => 'manager@example.com',
                'name'     => 'Manager User',
                'password' => 'Manager@123',
            ],
            [
                'email'    => 'editor@example.com',
                'name'     => 'Editor User',
                'password' => 'Editor@1234',
            ],
            [
                'email'    => 'support@example.com',
                'name'     => 'Support User',
                'password' => 'Support@123',
            ],
            [
                'email'    => 'viewer@example.com',
                'name'     => 'Viewer User',
                'password' => 'Viewer@1234',
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name'                  => $userData['name'],
                    'password'              => Hash::make($userData['password']),
                    'is_active'             => true,
                    'failed_login_attempts' => 0,
                ]
            );
        }

        $this->command->info('✅ Auth-service: 6 enterprise users seeded.');
        $this->command->newLine();
        $this->command->line('  <fg=green>admin@example.com</>       / Admin@123456');
        $this->command->line('  <fg=green>orgadmin@example.com</>    / OrgAdmin@123');
        $this->command->line('  <fg=green>manager@example.com</>     / Manager@123');
        $this->command->line('  <fg=green>editor@example.com</>      / Editor@1234');
        $this->command->line('  <fg=green>support@example.com</>     / Support@123');
        $this->command->line('  <fg=green>viewer@example.com</>      / Viewer@1234');
    }
}
