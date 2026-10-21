<?php

use App\Enums\TdrState;
use App\Models\Activity;
use App\Models\Tdr;
use App\Workflow\TdrWorkflow;
use App\Workflow\WorkflowException;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    private const FILE_RULES = ['required', 'file', 'mimes:pdf,doc,docx,odt', 'max:20480'];

    #[Locked]
    public int $activityId;

    /** Affiché dans la page Validations : met l'accent sur la décision. */
    public bool $reviewMode = false;

    public string $title = '';
    public $file;
    public string $comment = '';
    public string $granted = '';
    public bool $injunction = false;
    public $finalFile;
    public string $lessons = '';
    public string $risks = '';
    public string $recommendations = '';

    public function mount(int $activityId, bool $reviewMode = false): void
    {
        $this->activityId = $activityId;
        $this->reviewMode = $reviewMode;
        $this->authorize('view', $this->activity);
    }

    #[Computed]
    public function activity(): Activity
    {
        return Activity::with('direction')->findOrFail($this->activityId);
    }

    #[Computed]
    public function tdr(): ?Tdr
    {
        return $this->activity->tdr()
            ->with(['author', 'currentStep', 'returnedFromStep', 'circuit.steps', 'documents.uploader', 'transitions.user', 'transitions.fromStep', 'transitions.toStep'])
            ->first();
    }

    /** Le dernier commentaire de renvoi, affiché en tête quand le TDR est à corriger. */
    #[Computed]
    public function lastReturn()
    {
        return $this->tdr?->transitions->firstWhere('action', \App\Enums\WorkflowAction::ChangesRequested);
    }

    public function deposit(TdrWorkflow $workflow): void
    {
        $this->validate(['title' => ['required', 'string', 'max:255'], 'file' => self::FILE_RULES], attributes: ['title' => 'intitulé', 'file' => 'fichier']);

        $this->run(fn () => $workflow->create($this->activity, auth()->user(), $this->title, $this->file), 'TDR déposé. Vérifiez-le puis soumettez-le à validation.');
        $this->reset('title', 'file');
    }

    public function uploadVersion(TdrWorkflow $workflow): void
    {
        $this->validate(['file' => self::FILE_RULES], attributes: ['file' => 'fichier']);

        $this->run(fn () => $workflow->replaceDocument($this->tdr, auth()->user(), $this->file, $this->comment ?: null), 'Nouvelle version enregistrée.', 'tdr-version');
        $this->reset('file', 'comment');
    }

    public function submit(TdrWorkflow $workflow): void
    {
        $resubmit = $this->tdr->state === TdrState::ChangesRequested;
        $this->run(fn () => $workflow->submit($this->tdr, auth()->user(), $this->comment ?: null), $resubmit ? 'TDR resoumis.' : 'TDR soumis à validation.', 'tdr-submit');
        $this->reset('comment');
    }

    public function approve(TdrWorkflow $workflow): void
    {
        $opinion = $this->tdr->currentStep?->gives_opinion;
        if ($opinion) {
            $this->validate(['granted' => ['required', 'in:1,0']], ['granted.required' => 'Indiquez si le TDR est accordé.']);
        }

        $this->run(fn () => $workflow->approve(
            $this->tdr,
            auth()->user(),
            $this->comment ?: null,
            $opinion ? $this->granted === '1' : null,
            $opinion ? $this->injunction : null,
        ), 'Décision enregistrée : TDR validé.', 'tdr-approve', decided: true);
        $this->reset('comment', 'granted', 'injunction');
    }

    public function requestChanges(TdrWorkflow $workflow): void
    {
        $this->validate(['comment' => ['required', 'string', 'min:5']], ['comment.required' => 'Expliquez ce qui doit être corrigé.', 'comment.min' => 'Expliquez ce qui doit être corrigé.']);

        $this->run(fn () => $workflow->requestChanges($this->tdr, auth()->user(), $this->comment), 'TDR renvoyé au point focal.', 'tdr-return', decided: true);
        $this->reset('comment');
    }

    public function abandon(TdrWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->abandon($this->tdr, auth()->user()), 'TDR abandonné. Vous pouvez en déposer un nouveau.');
    }

    public function start(TdrWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->start($this->tdr, auth()->user()), 'Exécution démarrée.');
    }

    public function close(TdrWorkflow $workflow): void
    {
        $this->validate([
            'finalFile' => self::FILE_RULES,
            'lessons' => ['required', 'string', 'min:10'],
            'risks' => ['nullable', 'string'],
            'recommendations' => ['nullable', 'string'],
        ], attributes: ['finalFile' => 'TDR de fin d\'activité', 'lessons' => 'leçons apprises']);

        $this->run(fn () => $workflow->close($this->tdr, auth()->user(), $this->finalFile, [
            'lessons' => $this->lessons,
            'risks' => $this->risks ?: null,
            'recommendations' => $this->recommendations ?: null,
        ]), 'Activité clôturée.', 'tdr-close');
        $this->reset('finalFile', 'lessons', 'risks', 'recommendations');
    }

    private function run(callable $action, string $success, ?string $modal = null, bool $decided = false): void
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
        unset($this->tdr, $this->lastReturn);
        Flux::toast($success, variant: 'success');
        $this->dispatch($decided ? 'tdr-decided' : 'tdr-updated', tdrId: $this->tdr?->id);
    }
}; ?>

<div class="space-y-6">
    @if (! $this->tdr)
        {{-- Pas encore de TDR --}}
        @can('depositTdr', $this->activity)
            <flux:card class="space-y-5">
                <div>
                    <flux:heading size="lg">Déposer le TDR</flux:heading>
                    <flux:text class="mt-1">Les termes de référence seront vérifiés par le circuit de validation avant le démarrage de l'activité.</flux:text>
                </div>
                <form wire:submit="deposit" class="space-y-4">
                    <flux:input wire:model="title" label="Intitulé du TDR" placeholder="TDR — {{ \Illuminate\Support\Str::limit($this->activity->label, 60) }}" required />
                    <flux:input type="file" wire:model="file" label="Fichier" description="PDF, Word ou OpenDocument, 20 Mo maximum." accept=".pdf,.doc,.docx,.odt" />
                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" icon="arrow-up-tray">Déposer</flux:button>
                    </div>
                </form>
            </flux:card>
        @else
            <flux:card class="flex flex-col items-center gap-2 py-10 text-center">
                <flux:icon.document-text class="size-10 text-zinc-300 dark:text-zinc-600" />
                <flux:heading>Aucun TDR déposé</flux:heading>
                <flux:text class="max-w-sm">
                    {{ $this->activity->direction ? 'Le point focal de la direction '.$this->activity->direction->code.' dépose le TDR depuis cette page.' : 'Rattachez d\'abord l\'activité à une direction : son point focal pourra alors déposer le TDR.' }}
                </flux:text>
            </flux:card>
        @endcan
    @else
        @php($tdr = $this->tdr)

        <flux:card class="space-y-6">
            {{-- En-tête --}}
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="lg">{{ $tdr->title }}</flux:heading>
                        <x-tdr.state :tdr="$tdr" />
                    </div>
                    <flux:text size="sm" class="mt-1">
                        Déposé par {{ $tdr->author?->name ?? '—' }} le {{ $tdr->created_at->translatedFormat('j F Y') }}
                        @if ($tdr->circuit) · circuit « {{ $tdr->circuit->name }} » v{{ $tdr->circuit->version }} @endif
                    </flux:text>
                </div>

                {{-- Actions du point focal --}}
                <div class="flex flex-wrap gap-2">
                    @can('edit', $tdr)
                        <flux:modal.trigger name="tdr-version">
                            <flux:button size="sm" icon="arrow-up-tray">Nouvelle version</flux:button>
                        </flux:modal.trigger>
                        <flux:modal.trigger name="tdr-submit">
                            <flux:button size="sm" variant="primary" icon="paper-airplane">
                                {{ $tdr->state === TdrState::ChangesRequested ? 'Resoumettre' : 'Soumettre à validation' }}
                            </flux:button>
                        </flux:modal.trigger>
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Autres actions" />
                            <flux:menu>
                                <flux:menu.item icon="x-circle" variant="danger" wire:click="abandon"
                                    wire:confirm="Abandonner ce TDR ? Il restera dans l'historique et vous pourrez en déposer un autre.">Abandonner le TDR</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endcan
                    @can('execute', $tdr)
                        @if ($tdr->state === TdrState::Approved)
                            <flux:button size="sm" variant="primary" icon="play" wire:click="start">Démarrer l'exécution</flux:button>
                        @endif
                        <flux:modal.trigger name="tdr-close">
                            <flux:button size="sm" icon="flag">Clôturer l'activité</flux:button>
                        </flux:modal.trigger>
                    @endcan
                </div>
            </div>

            {{-- Messages contextuels --}}
            @if ($tdr->state === TdrState::ChangesRequested && $this->lastReturn)
                <flux:callout color="orange" icon="arrow-uturn-left">
                    <flux:callout.heading>Renvoyé par {{ $this->lastReturn->fromStep?->label }} ({{ $this->lastReturn->user?->name }})</flux:callout.heading>
                    <flux:callout.text>« {{ $this->lastReturn->comment }} »</flux:callout.text>
                    <flux:callout.text>Déposez une version corrigée puis resoumettez : le dossier reprendra à l'étape {{ $tdr->returnedFromStep?->label }}.</flux:callout.text>
                </flux:callout>
            @endif

            @can('decide', $tdr)
                <div class="rounded-lg border border-prseb-navy-200 bg-prseb-navy-50 p-4 dark:border-prseb-navy-700 dark:bg-prseb-navy-900/40">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="font-semibold text-prseb-navy-700 dark:text-prseb-navy-200">Votre décision est attendue — {{ $tdr->currentStep->label }}</div>
                            <flux:text size="sm">En attente depuis {{ $tdr->step_entered_at?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) ?? '—' }}.</flux:text>
                        </div>
                        <div class="flex gap-2">
                            <flux:modal.trigger name="tdr-return">
                                <flux:button icon="arrow-uturn-left">Renvoyer</flux:button>
                            </flux:modal.trigger>
                            <flux:modal.trigger name="tdr-approve">
                                <flux:button variant="primary" icon="check">Valider</flux:button>
                            </flux:modal.trigger>
                        </div>
                    </div>
                </div>
            @elseif ($tdr->state === TdrState::InReview && $this->reviewMode)
                <flux:callout icon="information-circle" color="zinc" heading="En attente de {{ $tdr->currentStep?->label }}." />
            @endif

            {{-- Circuit --}}
            <div class="overflow-x-auto pb-1">
                <x-tdr.stepper :tdr="$tdr" />
            </div>

            @if ($tdr->coordination_granted !== null)
                <div class="flex flex-wrap gap-2">
                    <flux:badge :color="$tdr->coordination_granted ? 'green' : 'red'" icon="{{ $tdr->coordination_granted ? 'check' : 'x-mark' }}">
                        {{ $tdr->coordination_granted ? 'Accordé' : 'Non accordé' }}
                    </flux:badge>
                    @if ($tdr->injunction)
                        <flux:badge color="orange" icon="exclamation-triangle">Injonction</flux:badge>
                    @endif
                </div>
            @endif

            {{-- Documents --}}
            <div>
                <flux:subheading class="mb-2">Documents</flux:subheading>
                <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($tdr->documents as $document)
                        <li @class(['flex items-center gap-3 px-3 py-2 text-sm', 'opacity-60' => ! $loop->first && $document->kind === 'tdr'])>
                            <flux:icon :icon="$document->isPdf() ? 'document' : 'document-text'" class="size-5 shrink-0 text-prseb-navy-600 dark:text-prseb-navy-300" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">
                                    {{ $document->kind === 'final' ? 'TDR de fin d\'activité' : 'TDR' }} · v{{ $document->version }}
                                    <span class="font-normal text-zinc-500 dark:text-zinc-400">— {{ $document->original_name }}</span>
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $document->humanSize() }} · {{ $document->uploader?->name }} · {{ $document->created_at->translatedFormat('j M Y, H:i') }}</div>
                            </div>
                            @if ($document->isPdf())
                                <flux:button size="xs" variant="ghost" icon="eye" :href="route('tdr-documents.show', [$document, 'apercu' => 1])" target="_blank">Aperçu</flux:button>
                            @endif
                            <flux:button size="xs" variant="ghost" icon="arrow-down-tray" :href="route('tdr-documents.show', $document)" aria-label="Télécharger" />
                        </li>
                    @endforeach
                </ul>
            </div>

            @if ($tdr->state === TdrState::Closed)
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach (['lessons' => 'Leçons apprises', 'risks' => 'Risques', 'recommendations' => 'Recommandations'] as $field => $label)
                        <div>
                            <flux:subheading>{{ $label }}</flux:subheading>
                            <flux:text class="mt-1 whitespace-pre-line">{{ $tdr->{$field} ?: '—' }}</flux:text>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Historique --}}
            <div>
                <flux:subheading class="mb-3">Historique</flux:subheading>
                <ol class="relative space-y-4 border-s border-zinc-200 ps-6 dark:border-zinc-700">
                    @foreach ($tdr->transitions as $transition)
                        <li class="relative">
                            <span class="absolute -start-[34px] flex size-5 items-center justify-center rounded-full bg-white ring-4 ring-white dark:bg-zinc-800 dark:ring-zinc-800">
                                <flux:icon :icon="$transition->action->icon()" variant="mini" class="size-5 {{ $transition->action->color() }}" />
                            </span>
                            <div class="text-sm">
                                <span class="font-medium">{{ $transition->action->label() }}</span>
                                @if ($transition->fromStep) <span class="text-zinc-500 dark:text-zinc-400">· {{ $transition->fromStep->label }}</span> @endif
                                @if ($transition->toStep && $transition->action !== \App\Enums\WorkflowAction::Approved) <span class="text-zinc-500 dark:text-zinc-400">→ {{ $transition->toStep->label }}</span> @endif
                                @if ($transition->toStep && $transition->action === \App\Enums\WorkflowAction::Approved) <span class="text-zinc-500 dark:text-zinc-400">→ transmis à {{ $transition->toStep->label }}</span> @endif
                                @if (isset($transition->meta['granted']))
                                    <flux:badge size="sm" :color="$transition->meta['granted'] ? 'green' : 'red'" class="ms-1">{{ $transition->meta['granted'] ? 'Accordé' : 'Non accordé' }}</flux:badge>
                                    @if ($transition->meta['injunction'] ?? false) <flux:badge size="sm" color="orange">Injonction</flux:badge> @endif
                                @endif
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $transition->user?->name ?? 'Système' }} · {{ $transition->created_at->translatedFormat('j M Y, H:i') }}</div>
                            @if ($transition->comment)
                                <blockquote class="mt-1 rounded-md bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-200">« {{ $transition->comment }} »</blockquote>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </flux:card>

        {{-- Modales --}}
        <flux:modal name="tdr-version" class="w-full max-w-md">
            <form wire:submit="uploadVersion" class="space-y-5">
                <flux:heading size="lg">Nouvelle version du TDR</flux:heading>
                <flux:input type="file" wire:model="file" label="Fichier" description="La version précédente reste consultable dans l'historique." accept=".pdf,.doc,.docx,.odt" />
                <flux:textarea wire:model="comment" label="Ce qui a changé" rows="2" placeholder="Facultatif" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">Enregistrer</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="tdr-submit" class="w-full max-w-md">
            <form wire:submit="submit" class="space-y-5">
                <flux:heading size="lg">{{ $tdr->state === TdrState::ChangesRequested ? 'Resoumettre le TDR' : 'Soumettre à validation' }}</flux:heading>
                <flux:text>
                    @if ($tdr->state === TdrState::ChangesRequested)
                        Le dossier repart à l'étape <strong>{{ $tdr->returnedFromStep?->label }}</strong>.
                    @else
                        Le dossier part à l'étape <strong>{{ $tdr->effectiveCircuit()?->firstStep()?->label }}</strong>. Il ne sera plus modifiable sauf renvoi.
                    @endif
                </flux:text>
                <div class="text-sm">Fichier envoyé : <span class="font-medium">v{{ $tdr->document?->version }} — {{ $tdr->document?->original_name }}</span></div>
                <flux:textarea wire:model="comment" label="Message aux valideurs" rows="2" placeholder="Facultatif" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="paper-airplane">Envoyer</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="tdr-approve" class="w-full max-w-md">
            <form wire:submit="approve" class="space-y-5">
                <flux:heading size="lg">Valider — {{ $tdr->currentStep?->label }}</flux:heading>
                @php($next = $tdr->currentStep ? $tdr->circuit?->stepAfter($tdr->currentStep) : null)
                <flux:text>{{ $next ? 'Le dossier sera transmis à : '.$next->label.'.' : 'C\'est la dernière étape : le TDR sera approuvé.' }}</flux:text>
                @if ($tdr->currentStep?->gives_opinion)
                    <flux:radio.group wire:model="granted" label="Avis" variant="segmented">
                        <flux:radio value="1" label="Accordé" />
                        <flux:radio value="0" label="Non accordé" />
                    </flux:radio.group>
                    <flux:checkbox wire:model="injunction" label="Avec injonction" description="Conditions à respecter pendant l'exécution, à préciser en commentaire." />
                @endif
                <flux:textarea wire:model="comment" label="Commentaire" rows="3" placeholder="Facultatif" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="check">Valider</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="tdr-return" class="w-full max-w-md">
            <form wire:submit="requestChanges" class="space-y-5">
                <flux:heading size="lg">Renvoyer pour correction</flux:heading>
                <flux:text>Le point focal corrigera le TDR ; à la resoumission, il reviendra directement à votre étape.</flux:text>
                <flux:textarea wire:model="comment" label="Ce qui doit être corrigé" rows="4" required />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger" icon="arrow-uturn-left">Renvoyer</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="tdr-close" class="w-full max-w-lg">
            <form wire:submit="close" class="space-y-5">
                <flux:heading size="lg">Clôturer l'activité</flux:heading>
                <flux:input type="file" wire:model="finalFile" label="TDR de fin d'activité" accept=".pdf,.doc,.docx,.odt" />
                <flux:textarea wire:model="lessons" label="Leçons apprises" rows="3" required />
                <flux:textarea wire:model="risks" label="Risques" rows="2" />
                <flux:textarea wire:model="recommendations" label="Recommandations" rows="2" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="flag">Clôturer</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
