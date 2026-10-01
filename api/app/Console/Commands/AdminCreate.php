<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class AdminCreate extends Command
{
    protected $signature = 'absensi:admin-create {username : Username login} {email : Email login} {--name= : Nama tampilan}';

    protected $description = 'Buat akun admin panel web baru (tanpa password; atur dengan absensi:admin-password)';

    public function handle(): int
    {
        $data = [
            'username' => mb_strtolower(trim((string) $this->argument('username'))),
            'email' => mb_strtolower(trim((string) $this->argument('email'))),
        ];

        $validator = Validator::make($data, [
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $this->option('name') ?: $data['username'],
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => null,
        ]);

        $this->info("Admin {$user->username} <{$user->email}> dibuat (belum punya password).");
        $this->line("Atur password: php artisan absensi:admin-password {$user->username}");

        return self::SUCCESS;
    }
}
