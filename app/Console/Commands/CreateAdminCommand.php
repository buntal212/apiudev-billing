<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('udev:admin-create')]
#[Description('Membuat administrator pertama UDEV Billing')]
class CreateAdminCommand extends Command
{
    public function handle(): int
    {
        $name = $this->ask('Nama administrator');
        $username = mb_strtolower((string) $this->ask('Username administrator'));
        $email = $this->ask('Email administrator');
        $password = $this->secret('Password');
        $passwordConfirmation = $this->secret('Konfirmasi password');

        $validator = Validator::make([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'username' => $username,
            'email' => mb_strtolower($email),
            'password' => Hash::make($password),
            'is_admin' => true,
        ]);

        $this->info('Administrator UDEV berhasil dibuat.');

        return self::SUCCESS;
    }
}
