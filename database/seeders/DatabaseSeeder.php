<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with demo data for local development.
     * Every demo user's password is "password".
     */
    public function run(): void
    {
        User::factory()->admin()->create(['name' => 'Demo Admin', 'email' => 'admin@example.com']);
        User::factory()->provisioner()->create(['name' => 'Demo Provisioner', 'email' => 'provisioner@example.com']);

        $account = Account::factory()->create(['name' => 'Acme Dental']);
        User::factory()->owner($account)->create(['name' => 'Demo Owner', 'email' => 'owner@example.com']);
        User::factory()->member($account)->create(['name' => 'Demo User', 'email' => 'user@example.com']);
    }
}
