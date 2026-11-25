<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateWebsiteAdmin extends Command
{
    protected $signature = 'website:create-admin {email} {--name=Administrator} {--password-env=}';

    protected $description = 'Create an explicitly authorized admin (no public registration)';

    public function handle(): int
    {
        $email = $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('An account already exists. This command does not change existing credentials or permissions.');

            return self::FAILURE;
        }
        $variable = $this->option('password-env');
        $password = $variable ? getenv($variable) : $this->secret('Password (at least 12 characters)');
        if (! is_string($password) || strlen($password) < 12) {
            $this->error('An admin password of at least 12 characters is required.');

            return self::FAILURE;
        }
        $user = new User(['email' => $email, 'name' => $this->option('name'), 'password' => $password]);
        $user->forceFill(['is_admin' => true])->save();
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
