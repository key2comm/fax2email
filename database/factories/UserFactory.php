<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::AccountUser,
            'account_id' => Account::factory(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->staff(Role::Admin);
    }

    public function provisioner(): static
    {
        return $this->staff(Role::Provisioner);
    }

    public function owner(?Account $account = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::AccountOwner,
            'account_id' => $account?->id ?? $attributes['account_id'],
        ]);
    }

    public function member(?Account $account = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::AccountUser,
            'account_id' => $account?->id ?? $attributes['account_id'],
        ]);
    }

    private function staff(Role $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
            'account_id' => null,
        ]);
    }
}
