<?php

namespace App\Enums;

enum ReportState: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case ChangesRequested = 'changes_requested';
    case Accepted = 'accepted';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Submitted => 'Soumis',
            self::ChangesRequested => 'À corriger',
            self::Accepted => 'Validé',
            self::Published => 'Publié',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Submitted => 'blue',
            self::ChangesRequested => 'orange',
            self::Accepted => 'green',
            self::Published => 'emerald',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }

    /** L'obligation est remplie dès que le rapport est remis (soumis ou plus). */
    public function isDelivered(): bool
    {
        return in_array($this, [self::Submitted, self::Accepted, self::Published], true);
    }
}
