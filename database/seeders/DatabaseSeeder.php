<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Direction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RoleSeeder::class, DirectionSeeder::class, ExerciceSeeder::class, WorkflowCircuitSeeder::class]);

        $admin = User::firstOrCreate(['email' => config('sigpro.admin.email')], [
            'name' => 'Administrateur SIGPRO',
            'password' => config('sigpro.admin.password') ?: Str::password(20),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole(Role::Admin->value);

        if (app()->environment('local')) {
            $this->seedDemoUsers();
            $this->call([DemoActivitySeeder::class, DemoFleetSeeder::class]);
        }
    }

    /**
     * Un compte par rôle pour tester les menus et les circuits en local.
     * Mot de passe : SIGPRO_DEMO_PASSWORD (.env).
     */
    private function seedDemoUsers(): void
    {
        $password = config('sigpro.demo_password');
        if (! $password) {
            return;
        }

        $direction = Direction::where('code', 'DSPA')->first();

        foreach (Role::cases() as $role) {
            if ($role === Role::Admin) {
                continue;
            }

            $user = User::firstOrCreate(['email' => str_replace('_', '.', $role->value).'@sigpro.test'], [
                'name' => $role->label().' (démo)',
                'password' => $password,
                'direction_id' => $role->isScopedToDirection() ? $direction?->id : null,
                'email_verified_at' => now(),
            ]);
            $user->assignRole($role->value);
        }
    }
}
