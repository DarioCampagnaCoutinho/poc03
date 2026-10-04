<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Papel com acesso total: passa em qualquer verificação de permissão (Gate::before no AppServiceProvider).
     */
    public const SUPER_ADMIN = 'super-admin';

    /**
     * Nomes das permissões efetivas do usuário (diretas e via papéis); o super-admin tem todas.
     *
     * @return Collection<int, string>
     */
    public function allPermissionNames(): Collection
    {
        $permissions = $this->hasRole(self::SUPER_ADMIN) ? Permission::all() : $this->getAllPermissions();

        return $permissions->pluck('name')->sort()->values();
    }

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
}
