<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

Artisan::command('inbox:user {email?} {--name=} {--password=} {--role=admin : admin or agent}', function (?string $email = null) {
    $role = $this->option('role');
    if (! array_key_exists($role, User::ROLES)) {
        $this->error('Role must be admin or agent.');

        return 1;
    }

    $email ??= text('Email', required: true);
    $name = $this->option('name') ?: text('Name', default: 'Admin', required: true);
    $pass = $this->option('password') ?: password('Password (min 8 characters)', required: true);

    if (strlen($pass) < 8) {
        $this->error('Password must be at least 8 characters.');

        return 1;
    }

    User::updateOrCreate(['email' => $email], [
        'name' => $name,
        'password' => Hash::make($pass),
        'role' => $role,
        'active' => true,
    ]);
    $this->info("User {$email} saved as {$role}. You can now log in.");
    if ($role === 'agent') {
        $this->line('Give them page access in the Team screen.');
    }
})->purpose('Create or update an inbox login (use the Team screen for everything else)');
