<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'fax2email:create-admin
                            {--name= : The admin\'s full name}
                            {--email= : The admin\'s email address}
                            {--skip-if-exists : Do nothing if an Admin already exists (lets the installer be re-run)}';

    protected $description = 'Create an Admin user (used by the installer to set up the first login)';

    public function handle(): int
    {
        if ($this->option('skip-if-exists') && User::where('role', Role::Admin)->exists()) {
            $this->components->info('An Admin already exists. Skipping.');

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: text('Name', required: true);
        $email = $this->option('email') ?: text('Email address', required: true);
        $password = password('Password', required: true, hint: 'At least 8 characters.');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => strtolower($email), 'password' => $password]);
        $user->forceFill(['role' => Role::Admin, 'account_id' => null, 'email_verified_at' => now()])->save();

        $this->components->info("Admin {$user->email} created.");

        return self::SUCCESS;
    }
}
