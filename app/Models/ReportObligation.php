<?php

namespace App\Models;

use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rapport attendu d'une direction pour une période, avec sa date limite.
 */
class ReportObligation extends Model
{
    protected $fillable = ['exercice_id', 'type', 'periode_id', 'direction_id', 'due_on'];

    protected function casts(): array
    {
        return ['type' => ReportType::class, 'due_on' => 'date'];
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(Periode::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    /** Le rapport de la direction correspondant à cette obligation, s'il existe. */
    public function findReport(): ?Report
    {
        return Report::where('exercice_id', $this->exercice_id)
            ->where('type', $this->type)
            ->where('periode_id', $this->periode_id)
            ->where('direction_id', $this->direction_id)
            ->where('is_consolidated', false)
            ->latest('id')
            ->first();
    }

    /**
     * Statut d'affichage : attendu, en retard, ou l'état du rapport déposé.
     *
     * @return array{key: string, label: string, color: string}
     */
    public static function status(?Report $report, self $obligation): array
    {
        if ($report && $report->state->isDelivered()) {
            return ['key' => $report->state->value, 'label' => $report->state->label(), 'color' => $report->state->color()];
        }

        $late = $obligation->due_on->lt(today());
        if ($report) {
            return ['key' => $report->state->value, 'label' => $report->state->label().($late ? ' · en retard' : ''), 'color' => $late ? 'red' : $report->state->color()];
        }

        return $late
            ? ['key' => 'late', 'label' => 'En retard', 'color' => 'red']
            : ['key' => 'expected', 'label' => 'Attendu', 'color' => 'zinc'];
    }
}
