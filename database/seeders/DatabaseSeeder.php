<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Businesses come from real discovery runs only. Outside local
     * development, create the sign-in account with `php artisan app:create-user`.
     */
    public function run(): void
    {
        if (app()->environment('local')) {
            // Development-only account; the factory password is "password".
            User::factory()->create([
                'name' => 'Local Developer',
                'email' => 'dev@example.com',
            ]);
        }
    }
}
