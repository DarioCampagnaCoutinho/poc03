<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Papel (grupo) da Spatie, sempre no guard "web".
 *
 * Nas rotas com auth:sanctum o guard padrão da requisição vira "sanctum", que não tem provider.
 * Sem o guard fixo, grupos criados ou buscados pela API ficariam em um guard diferente do das
 * permissões e dos usuários.
 */
class Role extends SpatieRole
{
    protected $guard_name = 'web';
}
