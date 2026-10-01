<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class AdminPassword extends Command
{
    protected $signature = 'absensi:admin-password {username : Username (atau email) admin}';

    protected $description = 'Atur password admin panel web (ditanyakan tersembunyi, dua kali)';

    public function handle(): int
    {
        $user = User::findByLogin((string) $this->argument('username'));

        if ($user === null) {
            $this->error('Admin "'.$this->argument('username').'" tidak ditemukan.');

            return self::FAILURE;
        }

        $password = (string) $this->secret("Password baru untuk {$user->username} (min. 8 karakter)");
        $confirm = (string) $this->secret('Ulangi password baru');

        if (mb_strlen($password) < 8) {
            $this->error('Password minimal 8 karakter.');

            return self::FAILURE;
        }

        if (! hash_equals($password, $confirm)) {
            $this->error('Password tidak sama.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => $password, // di-hash oleh cast 'hashed'
            'remember_token' => null,
        ])->save();

        $this->info("Password untuk {$user->username} <{$user->email}> disimpan. Silakan login di /admin.");

        return self::SUCCESS;
    }
}
