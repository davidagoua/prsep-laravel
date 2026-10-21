<?php

namespace App\Enums;

enum WorkflowAction: string
{
    case Created = 'created';
    case NewVersion = 'new_version';
    case Submitted = 'submitted';
    case Resubmitted = 'resubmitted';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
    case Abandoned = 'abandoned';
    case Started = 'started';
    case Closed = 'closed';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Dépôt initial',
            self::NewVersion => 'Nouvelle version déposée',
            self::Submitted => 'Soumis pour validation',
            self::Resubmitted => 'Resoumis après correction',
            self::Approved => 'Validé',
            self::ChangesRequested => 'Renvoyé pour correction',
            self::Abandoned => 'Abandonné',
            self::Started => 'Exécution démarrée',
            self::Closed => 'Activité clôturée',
            self::Published => 'Publié',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Created, self::NewVersion => 'document-plus',
            self::Submitted, self::Resubmitted => 'paper-airplane',
            self::Approved => 'check-circle',
            self::ChangesRequested => 'arrow-uturn-left',
            self::Abandoned => 'x-circle',
            self::Started => 'play',
            self::Closed => 'flag',
            self::Published => 'megaphone',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved, self::Closed, self::Published => 'text-prseb-green-600',
            self::ChangesRequested => 'text-prseb-orange-500',
            self::Abandoned => 'text-red-500',
            default => 'text-prseb-navy-600 dark:text-prseb-navy-300',
        };
    }
}
