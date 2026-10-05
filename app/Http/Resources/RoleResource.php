<?php

namespace App\Http\Resources;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // O super-admin não tem permissões atribuídas, mas libera todas (Gate::before).
        $permissions = $this->name === User::SUPER_ADMIN ? Permission::all() : $this->permissions;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'permissions' => $permissions->pluck('name')->sort()->values(),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
