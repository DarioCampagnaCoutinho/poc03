<?php

namespace App\Policies;

use App\Models\User;

/**
 * Regras da administração de usuários além da permissão users.manage.
 * O super-admin passa por todas elas (Gate::before no AppServiceProvider).
 */
class UserPolicy
{
    /**
     * Só um super-admin altera, exclui ou muda os grupos e as permissões de outro super-admin.
     */
    public function manage(User $actor, User $user): bool
    {
        return ! $user->hasRole(User::SUPER_ADMIN);
    }

    /**
     * Só um super-admin pode dar o papel super-admin a alguém.
     */
    public function grantSuperAdmin(User $actor): bool
    {
        return false;
    }
}
