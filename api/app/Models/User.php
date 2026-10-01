<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Akun tanpa password belum bisa dipakai login. */
    public function hasPassword(): bool
    {
        return ! empty($this->password);
    }

    /** Cari admin berdasarkan username atau email (tanpa membedakan huruf besar/kecil). */
    public static function findByLogin(string $login): ?self
    {
        $login = mb_strtolower(trim($login));

        if ($login === '') {
            return null;
        }

        return static::query()
            ->when(
                str_contains($login, '@'),
                fn ($q) => $q->whereRaw('lower(email) = ?', [$login]),
                fn ($q) => $q->whereRaw('lower(username) = ?', [$login]),
            )
            ->first();
    }
}
