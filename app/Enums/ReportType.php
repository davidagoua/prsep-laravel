<?php

namespace App\Enums;

/**
 * Types de rapports repris de l'application Django.
 */
enum ReportType: string
{
    case MensuelProjet = 'mensuel_projet';
    case MensuelProgramme = 'mensuel_programme';
    case TrimestrielProjet = 'trimestriel_projet';
    case TrimestrielProgramme = 'trimestriel_programme';
    case Semestriel = 'semestriel';
    case Annuel = 'annuel';
    case Circonstancie = 'circonstancie';

    public function label(): string
    {
        return match ($this) {
            self::MensuelProjet => 'Fiche mensuelle — projet',
            self::MensuelProgramme => 'Fiche mensuelle — programme',
            self::TrimestrielProjet => 'Rapport trimestriel — projet',
            self::TrimestrielProgramme => 'Rapport trimestriel — programme',
            self::Semestriel => 'Rapport semestriel',
            self::Annuel => 'Rapport annuel',
            self::Circonstancie => 'Rapport circonstancié',
        };
    }

    /** Périodicité ; null pour un rapport ponctuel. */
    public function periodeType(): ?PeriodeType
    {
        return match ($this) {
            self::MensuelProjet, self::MensuelProgramme => PeriodeType::Mois,
            self::TrimestrielProjet, self::TrimestrielProgramme => PeriodeType::Trimestre,
            self::Semestriel => PeriodeType::Semestre,
            self::Annuel => PeriodeType::Annee,
            self::Circonstancie => null,
        };
    }

    public function isPeriodic(): bool
    {
        return $this->periodeType() !== null;
    }

    /** @return list<self> */
    public static function periodic(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isPeriodic()));
    }
}
