<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Permissão da Spatie, sempre no guard "web" (mesmo motivo do App\Models\Role).
 */
class Permission extends SpatiePermission
{
    protected $guard_name = 'web';
}
