<?php

namespace App\Notifications;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const SUBMITTED = 'submitted';

    public const RETURNED = 'returned';

    public const ACCEPTED = 'accepted';

    public const PUBLISHED = 'published';

    public function __construct(public Report $report, public string $kind, public ?string $comment = null) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail', 'broadcast'];
    }

    public function title(): string
    {
        return match ($this->kind) {
            self::SUBMITTED => 'Rapport à valider',
            self::RETURNED => 'Rapport renvoyé pour correction',
            self::ACCEPTED => 'Rapport validé',
            self::PUBLISHED => 'Nouveau rapport consolidé',
        };
    }

    public function message(): string
    {
        $title = Str::limit($this->report->title, 90);

        return match ($this->kind) {
            self::SUBMITTED => "« {$title} » a été remis et attend votre validation.",
            self::RETURNED => "« {$title} » doit être corrigé avant d'être resoumis.",
            self::ACCEPTED => "« {$title} » a été validé par la coordination.",
            self::PUBLISHED => "« {$title} » est disponible.",
        };
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

        return $mail->action('Ouvrir le rapport', route('reports.show', $this->report))->salutation('SIGPRO — suivi du PRSEP');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'report_'.$this->kind,
            'title' => $this->title(),
            'message' => $this->message(),
            'comment' => $this->comment,
            'url' => route('reports.show', $this->report),
            'report_id' => $this->report->id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }
}
