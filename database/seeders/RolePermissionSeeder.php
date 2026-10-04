<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Uma permissão por operação de tarefa.
     */
    public const PERMISSIONS = [
        'tasks.view',    // listar, consultar e ver a lixeira
        'tasks.create',
        'tasks.update',
        'tasks.delete',
        'tasks.restore',
    ];

    /**
     * Cria as permissões e os papéis (grupos de permissões).
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        // O super-admin não recebe permissões: o Gate::before libera tudo para ele.
        Role::findOrCreate(User::SUPER_ADMIN);
        Role::findOrCreate('editor')->syncPermissions(self::PERMISSIONS);
        Role::findOrCreate('leitor')->syncPermissions(['tasks.view']);
    }
}
