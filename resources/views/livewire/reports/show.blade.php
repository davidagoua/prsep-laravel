<?php

use App\Collabora\Discovery;
use App\Collabora\WopiFile;
use App\Enums\ReportState;
use App\Enums\Role;
use App\Models\Direction;
use App\Models\Report;
use App\Reporting\ReportWorkflow;
use App\Workflow\WorkflowException;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Report $report;

    public $file;
    public string $comment = '';
    /** @var list<string> */
    public array $roles = [];
    /** @var list<string> */
    public array $audienceDirections = [];

    public function mount(Report $report): void
    {
        $this->authorize('view', $report);
        $this->report = $report;
    }

    public function rendering($view): void
    {
        $view->title(\Illuminate\Support\Str::limit($this->report->title, 50));
    }

    #[Computed]
    public function details(): Report
    {
        return $this->report->fresh(['periode', 'direction', 'author', 'documents.uploader', 'transitions.user', 'audiences.direction', 'exercice']);
    }

    /** Ouverture dans Collabora possible pour la dernière version ? */
    #[Computed]
    public function canOpenOnline(): bool
    {
        $document = $this->details->documents->first();

        return $document && app(Discovery::class)->supports($document->extension());
    }

    #[Computed]
    public function directions()
    {
        return Direction::active()->orderBy('code')->get(['id', 'code']);
    }

    public function uploadVersion(ReportWorkflow $workflow): void
    {
        $this->validate(['file' => ['required', 'file', 'mimes:docx,doc,odt,pdf,xlsx,xls,ods,pptx', 'max:20480']], attributes: ['file' => 'fichier']);
        $this->run(fn () => $workflow->replaceDocument($this->report, auth()->user(), $this->file, $this->comment ?: null), 'Nouvelle version enregistrée.', 'report-version');
        $this->reset('file', 'comment');
    }

    public function submit(ReportWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->submit($this->report, auth()->user(), $this->comment ?: null), 'Rapport remis à la coordination.', 'report-submit');
        $this->reset('comment');
    }

    public function accept(ReportWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->accept($this->report, auth()->user(), $this->comment ?: null), 'Rapport validé.', 'report-accept');
        $this->reset('comment');
    }

    public function requestChanges(ReportWorkflow $workflow): void
    {
        $this->validate(['comment' => ['required', 'string', 'min:5']], ['comment.required' => 'Expliquez ce qui doit être corrigé.', 'comment.min' => 'Expliquez ce qui doit être corrigé.']);
        $this->run(fn () => $workflow->requestChanges($this->report, auth()->user(), $this->comment), 'Rapport renvoyé à la direction.', 'report-return');
        $this->reset('comment');
    }

    public function openPublish(): void
    {
        $this->roles = [Role::DirecteurExecutif->value, Role::Coordination->value];
        $this->audienceDirections = [];
        Flux::modal('report-publish')->show();
    }

    public function publish(ReportWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->publish($this->report, auth()->user(), $this->roles, array_map('intval', $this->audienceDirections)), 'Rapport publié.', 'report-publish');
    }

    private function run(callable $action, string $success, ?string $modal = null): void
    {
        try {
            $action();
        } catch (WorkflowException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        } catch (AuthorizationException) {
            Flux::toast('Vous n\'avez pas le droit d\'effectuer cette action.', variant: 'danger');

            return;
        }

        if ($modal) {
            Flux::modal($modal)->close();
        }
        $this->report->refresh();
        unset($this->details, $this->canOpenOnline);
        Flux::toast($success, variant: 'success');
    }
}; ?>

<div class="flex flex-col gap-6">
    @php($r = $this->details)
    <div>
        <flux:breadcrumbs class="mb-3">
            <flux:breadcrumbs.item :href="route('reports.index')" wire:navigate>Rapports</flux:breadcrumbs.item>
            @if ($r->periode && auth()->user()->seesAllDirections())
                <flux:breadcrumbs.item :href="route('reports.period', ['type' => $r->type->value, 'periode' => $r->periode_id])" wire:navigate>{{ $r->periode->label }}</flux:breadcrumbs.item>
            @endif
            <flux:breadcrumbs.item>{{ $r->is_consolidated ? 'Consolidé' : $r->direction?->code }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <div class="flex flex-wrap items-center gap-2">
            <flux:badge size="sm" variant="outline">{{ $r->type->label() }}</flux:badge>
            <flux:badge size="sm" :color="$r->state->color()">{{ $r->state->label() }}</flux:badge>
        </div>
        <flux:heading size="xl" level="1" class="mt-2">{{ $r->title }}</flux:heading>
        <flux:text class="mt-1">
            {{ $r->is_consolidated ? 'Rapport consolidé de la coordination' : 'Direction '.$r->direction?->code }} · déposé par {{ $r->author?->name }} le {{ $r->created_at->translatedFormat('j F Y') }}
        </flux:text>
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_320px]">
        <flux:card class="min-w-0 space-y-6">
            {{-- Actions --}}
            <div class="flex flex-wrap gap-2">
                @if ($document = $r->documents->first())
                    @if ($this->canOpenOnline)
                        <flux:button :variant="auth()->user()->can('edit', $r) ? 'primary' : 'filled'" icon="pencil-square"
                            :href="route('documents.editor', WopiFile::idFor($document))" target="_blank">
                            {{ auth()->user()->can('edit', $r) ? 'Modifier en ligne' : 'Ouvrir' }}
                        </flux:button>
                    @endif
                    <flux:button icon="arrow-down-tray" :href="route('report-documents.show', $document)">Télécharger</flux:button>
                @endif
                @can('edit', $r)
                    <flux:modal.trigger name="report-version">
                        <flux:button icon="arrow-up-tray">Nouvelle version</flux:button>
                    </flux:modal.trigger>
                    @if (! $r->is_consolidated)
                        <flux:modal.trigger name="report-submit">
                            <flux:button variant="primary" icon="paper-airplane">{{ $r->state === ReportState::ChangesRequested ? 'Remettre à nouveau' : 'Remettre à la coordination' }}</flux:button>
                        </flux:modal.trigger>
                    @endif
                @endcan
                @can('publish', $r)
                    <flux:button variant="primary" icon="megaphone" wire:click="openPublish">Publier</flux:button>
                @endcan
            </div>

            @can('decide', $r)
                <div class="rounded-lg border border-prseb-navy-200 bg-prseb-navy-50 p-4 dark:border-prseb-navy-700 dark:bg-prseb-navy-900/40">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="font-semibold text-prseb-navy-700 dark:text-prseb-navy-200">Ce rapport attend votre validation</div>
                        <div class="flex gap-2">
                            <flux:modal.trigger name="report-return"><flux:button icon="arrow-uturn-left">Renvoyer</flux:button></flux:modal.trigger>
                            <flux:modal.trigger name="report-accept"><flux:button variant="primary" icon="check">Valider</flux:button></flux:modal.trigger>
                        </div>
                    </div>
                </div>
            @endcan

            @if ($r->state === ReportState::ChangesRequested && ($return = $r->transitions->firstWhere('action', \App\Enums\WorkflowAction::ChangesRequested)))
                <flux:callout color="orange" icon="arrow-uturn-left" heading="Renvoyé par {{ $return->user?->name }}">
                    <flux:callout.text>« {{ $return->comment }} »</flux:callout.text>
                </flux:callout>
            @endif

            <div>
                <flux:subheading class="mb-2">Versions</flux:subheading>
                <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($r->documents as $doc)
                        <li @class(['flex items-center gap-3 px-3 py-2 text-sm', 'opacity-60' => ! $loop->first])>
                            <flux:icon.document-text class="size-5 shrink-0 text-prseb-navy-600 dark:text-prseb-navy-300" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">v{{ $doc->version }} <span class="font-normal text-zinc-500">— {{ $doc->original_name }}</span></div>
                                <div class="text-xs text-zinc-500">{{ $doc->humanSize() }} · {{ $doc->uploader?->name }} · {{ $doc->updated_at->translatedFormat('j M Y, H:i') }}</div>
                            </div>
                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('report-documents.show', $doc)" aria-label="Télécharger" />
                        </li>
                    @endforeach
                </ul>
            </div>

            <div>
                <flux:subheading class="mb-3">Historique</flux:subheading>
                <ol class="relative space-y-4 border-s border-zinc-200 ps-6 dark:border-zinc-700">
                    @foreach ($r->transitions as $t)
                        <li class="relative">
                            <span class="absolute -start-[34px] flex size-5 items-center justify-center rounded-full bg-white ring-4 ring-white dark:bg-zinc-800 dark:ring-zinc-800">
                                <flux:icon :icon="$t->action->icon()" variant="mini" class="size-5 {{ $t->action->color() }}" />
                            </span>
                            <div class="text-sm font-medium">{{ $t->action->label() }}</div>
                            <div class="text-xs text-zinc-500">{{ $t->user?->name }} · {{ $t->created_at->translatedFormat('j M Y, H:i') }}</div>
                            @if ($t->comment)
                                <blockquote class="mt-1 rounded-md bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-700/50">« {{ $t->comment }} »</blockquote>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </flux:card>

        <aside class="space-y-4">
            <flux:card class="space-y-3 text-sm">
                <flux:heading>Informations</flux:heading>
                <dl class="space-y-3">
                    <div><dt class="text-zinc-500">Exercice</dt><dd class="font-medium">{{ $r->exercice->label }}</dd></div>
                    @if ($r->periode)<div><dt class="text-zinc-500">Période</dt><dd class="font-medium">{{ $r->periode->label }}</dd></div>@endif
                    @if ($r->submitted_at)<div><dt class="text-zinc-500">Remis le</dt><dd class="font-medium">{{ $r->submitted_at->translatedFormat('j F Y') }}</dd></div>@endif
                    @if ($r->accepted_at)<div><dt class="text-zinc-500">Validé le</dt><dd class="font-medium">{{ $r->accepted_at->translatedFormat('j F Y') }}</dd></div>@endif
                    @if ($r->published_at)<div><dt class="text-zinc-500">Publié le</dt><dd class="font-medium">{{ $r->published_at->translatedFormat('j F Y') }}</dd></div>@endif
                </dl>
                @if ($r->audiences->isNotEmpty())
                    <div>
                        <div class="mb-1 text-zinc-500">Destinataires</div>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($r->audiences as $a)
                                <flux:badge size="sm">{{ $a->label() }}</flux:badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            </flux:card>
        </aside>
    </div>

    <flux:modal name="report-version" class="w-full max-w-md">
        <form wire:submit="uploadVersion" class="space-y-4">
            <flux:heading size="lg">Nouvelle version</flux:heading>
            <flux:input type="file" wire:model="file" label="Fichier" accept=".docx,.doc,.odt,.pdf,.xlsx,.xls,.ods,.pptx" />
            <flux:textarea wire:model="comment" label="Ce qui a changé" rows="2" placeholder="Facultatif" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="report-submit" class="w-full max-w-md">
        <form wire:submit="submit" class="space-y-4">
            <flux:heading size="lg">Remettre le rapport</flux:heading>
            <flux:text>La coordination sera prévenue. Le rapport ne sera plus modifiable, sauf renvoi.</flux:text>
            <flux:textarea wire:model="comment" label="Message" rows="2" placeholder="Facultatif" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="paper-airplane">Remettre</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="report-accept" class="w-full max-w-md">
        <form wire:submit="accept" class="space-y-4">
            <flux:heading size="lg">Valider le rapport</flux:heading>
            <flux:textarea wire:model="comment" label="Commentaire" rows="2" placeholder="Facultatif" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">Valider</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="report-return" class="w-full max-w-md">
        <form wire:submit="requestChanges" class="space-y-4">
            <flux:heading size="lg">Renvoyer pour correction</flux:heading>
            <flux:textarea wire:model="comment" label="Ce qui doit être corrigé" rows="4" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger" icon="arrow-uturn-left">Renvoyer</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="report-publish" class="w-full max-w-lg">
        <form wire:submit="publish" class="space-y-5">
            <flux:heading size="lg">Publier le rapport consolidé</flux:heading>
            <flux:checkbox.group wire:model="roles" label="Rôles destinataires">
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (Role::cases() as $role)
                        @continue($role === Role::Admin || $role === Role::GestionnaireParc)
                        <flux:checkbox :value="$role->value" :label="$role->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>
            <flux:checkbox.group wire:model="audienceDirections" label="Directions destinataires (toute la direction)">
                <div class="grid max-h-40 grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-5">
                    @foreach ($this->directions as $d)
                        <flux:checkbox :value="(string) $d->id" :label="$d->code" />
                    @endforeach
                </div>
            </flux:checkbox.group>
            <flux:text size="sm">Les destinataires sont notifiés et voient le rapport dans leur liste.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="megaphone">Publier</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
