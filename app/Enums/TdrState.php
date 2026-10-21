<?php

namespace App\Enums;

enum TdrState: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Closed = 'closed';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::InReview => 'En validation',
            self::ChangesRequested => 'À corriger',
            self::Approved => 'Approuvé',
            self::InProgress => 'En exécution',
            self::Closed => 'Clôturé',
            self::Abandoned => 'Abandonné',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::InReview => 'blue',
            self::ChangesRequested => 'orange',
            self::Approved => 'green',
            self::InProgress => 'sky',
            self::Closed => 'emerald',
            self::Abandoned => 'red',
        };
    }

    /** Le point focal peut encore modifier le dossier. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }

    /** Dossier encore vivant (un seul par activité). */
    public function isOpen(): bool
    {
        return $this !== self::Abandoned;
    }
}
