<?php

namespace App\Enums\Fleet;

/**
 * Types d'entretien repris de l'application Django.
 */
enum MaintenanceType: string
{
    case Vidange = 'vidange';
    case Lubrification = 'lubrification';
    case FiltreHuile = 'filtre_huile';
    case FiltreAir = 'filtre_air';
    case Transmission = 'transmission';
    case Refroidissement = 'refroidissement';
    case Alignement = 'alignement';
    case Pneumatiques = 'pneumatiques';
    case Freins = 'freins';
    case Moteur = 'moteur';
    case Reparation = 'reparation';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::Vidange => 'Vidange d\'huile',
            self::Lubrification => 'Lubrification du châssis',
            self::FiltreHuile => 'Changement du filtre à huile',
            self::FiltreAir => 'Changement du filtre à air',
            self::Transmission => 'Vidange du liquide de transmission',
            self::Refroidissement => 'Rinçage du système de refroidissement',
            self::Alignement => 'Alignement des roues',
            self::Pneumatiques => 'Remplacement des pneumatiques',
            self::Freins => 'Réglage des freins',
            self::Moteur => 'Réglage moteur',
            self::Reparation => 'Réparation',
            self::Autre => 'Autre service',
        };
    }
}
