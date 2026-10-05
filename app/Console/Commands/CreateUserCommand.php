<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The application has no public registration; accounts are created here.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'app:create-user {--name=} {--email=}';

    protected $description = 'Create a user who can sign in (or reset an existing user\'s password)';

    public function handle(): int
    {
        $email = $this->option('email') ?: text('E-mail', required: true, validate: ['email' => 'email']);
        $user = User::where('email', $email)->first();

        if ($user !== null && ! confirm("A user with {$email} exists. Reset their password?", default: false)) {
            return self::SUCCESS;
        }

        $name = $user?->name ?? ($this->option('name') ?: text('Name', default: 'Admin', required: true));
        $password = password('Password (min. 12 characters)', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user ??= new User(['email' => $email]);
        $user->fill(['name' => $name, 'password' => $password])->save();

        $this->info("User {$email} saved. Sign in at ".route('login'));

        return self::SUCCESS;
    }
}
