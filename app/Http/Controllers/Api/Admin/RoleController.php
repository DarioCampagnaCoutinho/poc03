<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller implements HasMiddleware
{
    /**
     * Toda a administração de grupos exige roles.manage.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:roles.manage')];
    }

    /**
     * Lista os grupos com suas permissões e o número de membros.
     */
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::with('permissions')->withCount('users')->orderBy('id')->get());
    }

    /**
     * Cria um grupo, já com suas permissões.
     */
    public function store(StoreRoleRequest $request): RoleResource
    {
        $role = DB::transaction(function () use ($request) {
            $role = Role::create(['name' => $request->validated('name')]);
            $role->syncPermissions($request->validated('permissions', []));

            return $role;
        });

        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    /**
     * Exibe um grupo.
     */
    public function show(Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    /**
     * Renomeia o grupo e/ou define a lista completa de permissões dele.
     */
    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        $this->ensureIsNotSuperAdmin($role);

        DB::transaction(function () use ($request, $role) {
            if ($request->has('name')) {
                $role->update(['name' => $request->validated('name')]);
            }

            if ($request->has('permissions')) {
                $role->syncPermissions($request->validated('permissions'));
            }
        });

        return new RoleResource($role->load('permissions')->loadCount('users'));
    }

    /**
     * Exclui o grupo; os membros perdem as permissões que vinham dele.
     */
    public function destroy(Role $role): Response
    {
        $this->ensureIsNotSuperAdmin($role);

        $role->delete();

        return response()->noContent();
    }

    /**
     * O grupo super-admin é estrutural: o Gate::before depende do nome dele.
     */
    private function ensureIsNotSuperAdmin(Role $role): void
    {
        abort_if($role->name === User::SUPER_ADMIN, 403, 'The super-admin role cannot be changed or deleted.');
    }
}
