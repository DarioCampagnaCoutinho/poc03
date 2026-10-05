<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Uma permissão por operação de tarefa.
     */
    public const TASK_PERMISSIONS = [
        'tasks.view',    // listar, consultar e ver a lixeira
        'tasks.create',
        'tasks.update',
        'tasks.delete',
        'tasks.restore',
    ];

    /**
     * Permissões de administração.
     */
    public const ADMIN_PERMISSIONS = [
        'users.manage',  // usuários e seus grupos e permissões
        'roles.manage',  // grupos (papéis) e suas permissões
    ];

    /**
     * Cria as permissões e os papéis (grupos de permissões).
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([...self::TASK_PERMISSIONS, ...self::ADMIN_PERMISSIONS] as $permission) {
            Permission::findOrCreate($permission);
        }

        // O super-admin não recebe permissões: o Gate::before libera tudo para ele.
        Role::findOrCreate(User::SUPER_ADMIN);
        Role::findOrCreate('editor')->syncPermissions(self::TASK_PERMISSIONS);
        Role::findOrCreate('leitor')->syncPermissions(['tasks.view']);
    }
}
