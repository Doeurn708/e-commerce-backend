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
     */
    public function run(): void
    {
        User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'Admin@123',
            'role' => 'admin',
        ]);

        User::query()->create([
            'name' => 'Customer',
            'email' => 'user@example.com',
            'password' => 'User@123',
            'role' => 'customer',
        ]);
    }
}