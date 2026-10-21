<?php

namespace App\Collabora;

use App\Models\ReportDocument;
use App\Models\TdrDocument;
use App\Models\User;
use App\Reporting\ReportWorkflow;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Document exposé à Collabora : version d'un rapport (modifiable par qui peut éditer le rapport)
 * ou d'un TDR (lecture seule, pour les valideurs).
 */
class WopiFile
{
    private function __construct(public readonly string $id, private readonly ReportDocument|TdrDocument $document) {}

    /** Identifiant WOPI : « report-12 » ou « tdr-7 ». */
    public static function find(string $id): ?self
    {
        if (! preg_match('/^(report|tdr)-(\d+)$/', $id, $m)) {
            return null;
        }

        $document = $m[1] === 'report' ? ReportDocument::with('report')->find($m[2]) : TdrDocument::with('tdr')->find($m[2]);

        return $document ? new self($id, $document) : null;
    }

    public static function idFor(ReportDocument|TdrDocument $document): string
    {
        return ($document instanceof ReportDocument ? 'report-' : 'tdr-').$document->id;
    }

    public function canView(User $user): bool
    {
        return Gate::forUser($user)->allows('view', $this->owner());
    }

    public function canWrite(User $user): bool
    {
        return $this->document instanceof ReportDocument
            && $this->document->is($this->document->report->document()->first())
            && Gate::forUser($user)->allows('edit', $this->document->report);
    }

    public function name(): string
    {
        return $this->document->original_name;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->document->path, PATHINFO_EXTENSION));
    }

    public function size(): int
    {
        return (int) $this->document->size;
    }

    public function version(): string
    {
        return (string) ($this->document->updated_at ?? $this->document->created_at)?->getTimestamp();
    }

    public function contents(): string
    {
        return Storage::disk($this->document->disk)->get($this->document->path);
    }

    public function write(User $user, string $contents): void
    {
        app(ReportWorkflow::class)->saveOnline($this->document, $user, $contents);
    }

    public function title(): string
    {
        return $this->document instanceof ReportDocument ? $this->document->report->title : $this->document->tdr->title;
    }

    public function backUrl(): string
    {
        return $this->document instanceof ReportDocument
            ? route('reports.show', $this->document->report_id)
            : route('activities.show', $this->document->tdr->activity_id);
    }

    private function owner(): mixed
    {
        return $this->document instanceof ReportDocument ? $this->document->report : $this->document->tdr;
    }
}
