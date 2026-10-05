<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PermissionController extends Controller
{
    /**
     * Lista as permissões existentes, para montar grupos e atribuir a usuários.
     */
    public function __invoke(): JsonResponse
    {
        Gate::allowIf(fn (User $user) => $user->canAny(['users.manage', 'roles.manage']));

        return response()->json(['data' => Permission::orderBy('name')->pluck('name')]);
    }
}
