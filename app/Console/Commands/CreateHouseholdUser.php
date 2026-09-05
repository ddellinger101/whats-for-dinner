<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Accounts are created here rather than through a registration form: the app
 * is for one household and should never accept a sign-up from the open web.
 */
class CreateHouseholdUser extends Command
{
    protected $signature = 'household:user
        {email : The account email}
        {--name= : Display name, defaults to the part before the @}
        {--password= : Set non-interactively; prompted for if omitted}';

    protected $description = 'Create or update a household account';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Not a valid email address: {$email}");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password');

        if (! $password || mb_strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: str($email)->before('@')->headline()->value();

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->info($user->wasRecentlyCreated
            ? "Created {$user->name} <{$user->email}>"
            : "Updated password for {$user->name} <{$user->email}>");

        return self::SUCCESS;
    }
}
