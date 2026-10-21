<?php

namespace App\Models;

use App\Enums\Volet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PtbaImport extends Model
{
    public const ANALYSED = 'analysed';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'exercice_id', 'user_id', 'volet', 'format', 'original_name', 'path', 'status',
        'summary', 'issues', 'progress', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'volet' => Volet::class,
            'summary' => 'array',
            'issues' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(Exercice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function errorCount(): int
    {
        return collect($this->issues)->where('level', 'error')->count();
    }

    public function warningCount(): int
    {
        return collect($this->issues)->where('level', 'warning')->count();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::ANALYSED => 'Analysé, en attente de validation',
            self::QUEUED => 'En file d\'attente',
            self::RUNNING => 'Import en cours',
            self::DONE => 'Importé',
            self::FAILED => 'Échec',
            self::CANCELLED => 'Abandonné',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            self::DONE => 'green',
            self::FAILED => 'red',
            self::RUNNING, self::QUEUED => 'blue',
            self::CANCELLED => 'zinc',
            default => 'amber',
        };
    }

    /** Arbre normalisé produit par l'analyse, conservé jusqu'à la validation. */
    public function parsedPath(): string
    {
        return "imports/ptba-{$this->id}.json";
    }
}
