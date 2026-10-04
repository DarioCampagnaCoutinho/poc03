<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // O super-admin passa em qualquer verificação de permissão (recomendação da Spatie).
        // Retorna null, e não false, para os demais usuários seguirem a verificação normal.
        Gate::before(fn (User $user) => $user->hasRole(User::SUPER_ADMIN) ? true : null);
    }
}
