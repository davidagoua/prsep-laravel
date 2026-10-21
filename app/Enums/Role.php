<?php

namespace App\Enums;

enum Role: string
{
    case PointFocal = 'point_focal';
    case DirecteurCentral = 'directeur_central';
    case Coordination = 'coordination';
    case DirecteurExecutif = 'directeur_executif';
    case GestionnaireParc = 'gestionnaire_parc';
    case Lecteur = 'lecteur';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::PointFocal => 'Point focal',
            self::DirecteurCentral => 'Directeur central',
            self::Coordination => 'Coordination',
            self::DirecteurExecutif => 'Directeur exécutif',
            self::GestionnaireParc => 'Gestionnaire du parc',
            self::Lecteur => 'Lecteur',
            self::Admin => 'Administrateur',
        };
    }

    /**
     * Les rôles limités aux données de la direction de l'utilisateur.
     */
    public function isScopedToDirection(): bool
    {
        return in_array($this, [self::PointFocal, self::DirecteurCentral], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $role) => [$role->value => $role->label()])->all();
    }
}
