<?php

namespace App\Notifications;

use App\Models\Tdr;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class TdrNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const ACTION_REQUIRED = 'action_required';

    public const RETURNED = 'returned';

    public const APPROVED = 'approved';

    public function __construct(
        public Tdr $tdr,
        public string $kind,
        public ?string $stepLabel = null,
        public ?string $comment = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail', 'broadcast'];
    }

    public function title(): string
    {
        return match ($this->kind) {
            self::ACTION_REQUIRED => 'TDR à valider',
            self::RETURNED => 'TDR renvoyé pour correction',
            self::APPROVED => 'TDR approuvé',
        };
    }

    public function message(): string
    {
        $activity = Str::limit($this->tdr->activity->label, 80);

        return match ($this->kind) {
            self::ACTION_REQUIRED => "« {$activity} » attend votre décision ({$this->stepLabel}).",
            self::RETURNED => "« {$activity} » doit être corrigé avant d'être resoumis.",
            self::APPROVED => "« {$activity} » est approuvé : l'activité peut démarrer.",
        };
    }

    public function url(): string
    {
        return $this->kind === self::ACTION_REQUIRED
            ? route('validations', ['tdr' => $this->tdr->id])
            : route('activities.show', $this->tdr->activity_id);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('[SIGPRO] '.$this->title())
            ->greeting('Bonjour '.Str::before($notifiable->name, ' ').',')
            ->line($this->message());

        if ($this->comment) {
            $mail->line('Commentaire : « '.$this->comment.' »');
        }

        return $mail
            ->action($this->kind === self::ACTION_REQUIRED ? 'Examiner le TDR' : 'Ouvrir l\'activité', $this->url())
            ->salutation('SIGPRO — suivi du PRSEP');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title(),
            'message' => $this->message(),
            'comment' => $this->comment,
            'url' => $this->url(),
            'tdr_id' => $this->tdr->id,
            'activity_id' => $this->tdr->activity_id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
