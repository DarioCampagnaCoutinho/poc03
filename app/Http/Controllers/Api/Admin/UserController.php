<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\SyncUserPermissionsRequest;
use App\Http\Requests\Admin\SyncUserRolesRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserController extends Controller implements HasMiddleware
{
    /**
     * Relações usadas pelo UserResource (papéis e permissões efetivas).
     */
    private const RELATIONS = ['roles.permissions', 'permissions'];

    /**
     * Toda a administração de usuários exige users.manage.
     * As proteções do super-admin ficam na UserPolicy e nos Form Requests.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('can:users.manage')];
    }

    /**
     * Lista os usuários. Filtro opcional por grupo: ?role=leitor.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'role' => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $users = User::with(self::RELATIONS)
            ->when($request->filled('role'), fn (Builder $query) => $query->role($request->input('role')))
            ->orderBy('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return UserResource::collection($users);
    }

    /**
     * Cria um usuário, já com seus grupos e permissões diretas.
     */
    public function store(StoreUserRequest $request): UserResource
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->syncRoles($request->validated('roles', []));
            $user->syncPermissions($request->validated('permissions', []));

            return $user;
        });

        return new UserResource($user->load(self::RELATIONS));
    }

    /**
     * Exibe um usuário.
     */
    public function show(User $user): UserResource
    {
        return new UserResource($user->load(self::RELATIONS));
    }

    /**
     * Atualiza nome, e-mail ou senha (apenas os campos enviados).
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $user->update($request->validated());

        return new UserResource($user->load(self::RELATIONS));
    }

    /**
     * Define a lista completa de grupos do usuário.
     */
    public function syncRoles(SyncUserRolesRequest $request, User $user): UserResource
    {
        $roles = $request->validated('roles');

        // Garante que sempre reste um super-admin: ninguém tira esse papel de si mesmo.
        abort_if(
            $user->is($request->user()) && $user->hasRole(User::SUPER_ADMIN) && ! in_array(User::SUPER_ADMIN, $roles, true),
            403,
            'You cannot remove your own super-admin role.',
        );

        $user->syncRoles($roles);

        return new UserResource($user->load(self::RELATIONS));
    }

    /**
     * Define a lista completa de permissões diretas do usuário (além das dos grupos).
     */
    public function syncPermissions(SyncUserPermissionsRequest $request, User $user): UserResource
    {
        $user->syncPermissions($request->validated('permissions'));

        return new UserResource($user->load(self::RELATIONS));
    }

    /**
     * Exclui o usuário e revoga seus tokens.
     */
    public function destroy(Request $request, User $user): Response
    {
        abort_if($user->is($request->user()), 403, 'You cannot delete your own account.');
        Gate::authorize('manage', $user);

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->noContent();
    }
}
