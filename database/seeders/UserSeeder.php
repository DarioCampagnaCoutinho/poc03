<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * Usuários de desenvolvimento (senha "password") e seus papéis.
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Dario', 'email' => 'dario@example.com', 'role' => User::SUPER_ADMIN],
            ['name' => 'Maria', 'email' => 'maria@example.com', 'role' => 'leitor'],
            ['name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'leitor'],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                ['name' => $user['name'], 'password' => 'password'],
            )->assignRole($user['role']);
        }
    }
}
