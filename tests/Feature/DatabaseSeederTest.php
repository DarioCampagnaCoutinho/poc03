<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_roles_with_their_permissions(): void
    {
        $this->seed();

        $this->assertSame([], Role::findByName(User::SUPER_ADMIN)->permissions->pluck('name')->all());
        $this->assertSame(['tasks.view'], Role::findByName('leitor')->permissions->pluck('name')->all());
        $this->assertCount(5, Role::findByName('editor')->permissions);
    }

    public function test_seeds_development_users_with_their_roles(): void
    {
        $this->seed();

        $roles = User::with('roles')->orderBy('id')->get()
            ->mapWithKeys(fn (User $user) => [$user->email => $user->getRoleNames()->all()])
            ->all();

        $this->assertSame([
            'dario@example.com' => [User::SUPER_ADMIN],
            'maria@example.com' => ['leitor'],
            'ana@example.com' => ['leitor'],
        ], $roles);

        $this->assertTrue(Hash::check('password', User::firstWhere('email', 'dario@example.com')->password));
    }

    public function test_seeding_twice_does_not_duplicate_data(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(3, User::count());
        $this->assertSame(3, Role::count());
        $this->assertSame(5, Permission::count());
    }
}
