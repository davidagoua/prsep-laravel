<?php

namespace App\Reporting;

use App\Enums\ReportType;
use App\Models\Exercice;
use App\Models\ReportObligation;
use Carbon\CarbonInterface;

/**
 * Crée les rapports attendus d'un exercice : un par type, période et direction,
 * dû N jours après la fin de la période. Les obligations existantes sont conservées ;
 * avec une date de départ, les périodes déjà terminées avant cette date sont ignorées.
 */
class ObligationGenerator
{
    /**
     * @param  list<ReportType>  $types
     * @param  list<int>  $directionIds
     * @param  CarbonInterface|null  $from  ignorer les périodes terminées avant cette date
     */
    public function generate(Exercice $exercice, array $types, array $directionIds, int $delayDays, ?CarbonInterface $from = null): int
    {
        $created = 0;

        foreach ($types as $type) {
            $periodes = $exercice->periodes()->where('type', $type->periodeType())
                ->when($from, fn ($q) => $q->whereDate('ends_on', '>=', $from))
                ->get();

            foreach ($periodes as $periode) {
                foreach ($directionIds as $directionId) {
                    $obligation = ReportObligation::firstOrCreate([
                        'exercice_id' => $exercice->id,
                        'type' => $type,
                        'periode_id' => $periode->id,
                        'direction_id' => $directionId,
                    ], ['due_on' => $periode->ends_on->copy()->addDays($delayDays)]);

                    $created += (int) $obligation->wasRecentlyCreated;
                }
            }
        }

        return $created;
    }
}
