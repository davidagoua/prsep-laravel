<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Exercice;
use App\Models\User;
use App\Support\CurrentExercice;
use App\Support\Navigation;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentExercice::class, fn ($app) => new CurrentExercice($app['session.store']));
    }

    public function boot(): void
    {
        // L'administrateur a tous les droits, quelles que soient les policies.
        Gate::before(fn (User $user) => $user->hasRole(Role::Admin->value) ? true : null);

        Event::listen(Login::class, function (Login $event) {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });

        View::composer('components.layouts.app.sidebar', function ($view) {
            $user = auth()->user();

            $view->with([
                'navigation' => $user ? Navigation::for($user) : [],
                'currentExercice' => app(CurrentExercice::class)->get(),
                'exercices' => Exercice::query()->orderByDesc('year')->get(['id', 'label', 'year', 'is_current']),
            ]);
        });
    }
}
