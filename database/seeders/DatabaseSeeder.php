<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Safe to run repeatedly (and on every deploy): accounts are looked up by
     * email with firstOrCreate, so an existing admin is never overwritten and a
     * second run cannot fail on the unique email index. The admin credentials
     * come from ADMIN_EMAIL / ADMIN_PASSWORD when set, which is how a
     * production deploy bootstraps its first admin without committing a
     * password to the repository.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'password' => env('ADMIN_PASSWORD', 'Admin@123'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        // The demo customer only exists locally; production should not ship a
        // known-password account.
        if (! app()->environment('production')) {
            User::query()->firstOrCreate(
                ['email' => 'user@example.com'],
                [
                    'name' => 'Customer',
                    'password' => 'User@123',
                    'role' => 'customer',
                    'is_active' => true,
                ]
            );
        }
    }
}
