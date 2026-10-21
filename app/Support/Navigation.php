<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;

/**
 * Menu principal. Chaque entrée déclare les rôles qui la voient ;
 * `lot` signale un module pas encore livré (affiché désactivé).
 */
class Navigation
{
    /**
     * @return list<array{heading: ?string, items: list<array{label: string, icon: string, route: ?string, active: string, lot: ?int}>}>
     */
    public static function for(User $user): array
    {
        $all = Role::cases();
        $metier = [Role::PointFocal, Role::DirecteurCentral, Role::Coordination, Role::DirecteurExecutif, Role::Lecteur, Role::Admin];
        $valideurs = [Role::DirecteurCentral, Role::Coordination, Role::DirecteurExecutif, Role::Admin];
        $pilotage = [Role::Coordination, Role::DirecteurExecutif, Role::Lecteur, Role::Admin];

        $groups = [
            [
                'heading' => null,
                'items' => [
                    self::item('Accueil', 'home', $all, route: 'dashboard'),
                    self::item('À traiter', 'inbox-stack', $all, lot: 2),
                ],
            ],
            [
                'heading' => 'Pilotage',
                'items' => [
                    self::item('Activités', 'rectangle-stack', $metier, lot: 1),
                    self::item('Validations', 'check-badge', $valideurs, lot: 2),
                    self::item('Suivi financier', 'banknotes', $pilotage, lot: 3),
                    self::item('Rapports', 'document-text', $metier, lot: 4),
                    self::item('Carte', 'map', $metier, lot: 5),
                ],
            ],
            [
                'heading' => 'Logistique',
                'items' => [
                    self::item('Parc automobile', 'truck', [Role::GestionnaireParc, Role::Admin], lot: 6),
                ],
            ],
            [
                'heading' => 'Administration',
                'items' => [
                    self::item('Utilisateurs', 'users', [Role::Admin], route: 'admin.users', active: 'admin.users*'),
                    self::item('Directions', 'building-office-2', [Role::Admin], route: 'admin.directions', active: 'admin.directions*'),
                    self::item('Exercices', 'calendar-days', [Role::Admin], route: 'admin.exercices', active: 'admin.exercices*'),
                ],
            ],
        ];

        return collect($groups)
            ->map(function (array $group) use ($user) {
                $group['items'] = collect($group['items'])
                    ->filter(fn (array $item) => $user->hasAppRole(...$item['roles']))
                    ->map(fn (array $item) => collect($item)->except('roles')->all())
                    ->values()
                    ->all();

                return $group;
            })
            ->filter(fn (array $group) => $group['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  list<Role>  $roles
     */
    private static function item(string $label, string $icon, array $roles, ?string $route = null, ?string $active = null, ?int $lot = null): array
    {
        return [
            'label' => $label,
            'icon' => $icon,
            'roles' => $roles,
            'route' => $route,
            'active' => $active ?? $route ?? '',
            'lot' => $lot,
        ];
    }
}
