<?php

use App\Enums\Role;
use App\Enums\StepScope;
use App\Enums\TdrState;
use App\Enums\Volet;
use App\Enums\WorkflowSubject;
use App\Models\Tdr;
use App\Models\WorkflowCircuit;
use App\Workflow\CircuitPublisher;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Circuits de validation')] class extends Component {
    public ?string $editing = null;
    public string $name = '';
    /** @var list<array{label: string, role: string, scope: string, gives_opinion: bool}> */
    public array $steps = [];

    #[Computed]
    public function circuits(): array
    {
        return collect(Volet::cases())->mapWithKeys(function (Volet $volet) {
            $versions = WorkflowCircuit::where('subject', WorkflowSubject::Tdr)->where('volet', $volet)
                ->with('steps')->orderByDesc('version')->get();
            $active = $versions->firstWhere('is_active', true);

            return [$volet->value => [
                'volet' => $volet,
                'active' => $active,
                'history' => $versions->reject(fn ($c) => $c->is($active))->values(),
                'inFlight' => $active ? Tdr::where('circuit_id', $active->id)->whereIn('state', [TdrState::InReview, TdrState::ChangesRequested])->count() : 0,
                'used' => $active?->isUsed() ?? false,
            ]];
        })->all();
    }

    public function edit(string $volet): void
    {
        $circuit = $this->circuits[$volet]['active'];
        $this->editing = $volet;
        $this->name = $circuit?->name ?? 'Validation des TDR — '.Volet::from($volet)->label();
        $this->steps = $circuit
            ? $circuit->steps->map(fn ($s) => [
                'label' => $s->label, 'role' => $s->role->value, 'scope' => $s->scope->value, 'gives_opinion' => $s->gives_opinion,
            ])->all()
            : [];
        if ($this->steps === []) {
            $this->addStep();
        }
        $this->resetValidation();
    }

    public function addStep(): void
    {
        $this->steps[] = ['label' => '', 'role' => Role::Coordination->value, 'scope' => StepScope::Global->value, 'gives_opinion' => false];
    }

    public function removeStep(int $index): void
    {
        unset($this->steps[$index]);
        $this->steps = array_values($this->steps);
    }

    public function move(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (! isset($this->steps[$index], $this->steps[$target])) {
            return;
        }
        [$this->steps[$index], $this->steps[$target]] = [$this->steps[$target], $this->steps[$index]];
    }

    public function cancel(): void
    {
        $this->reset('editing', 'name', 'steps');
    }

    public function save(CircuitPublisher $publisher): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'steps' => ['required', 'array', 'min:1', 'max:8'],
            'steps.*.label' => ['required', 'string', 'max:100'],
            'steps.*.role' => ['required', Rule::enum(Role::class)],
            'steps.*.scope' => ['required', Rule::enum(StepScope::class)],
            'steps.*.gives_opinion' => ['boolean'],
        ], [
            'steps.*.label.required' => 'Donnez un libellé à chaque étape.',
            'steps.required' => 'Le circuit doit comporter au moins une étape.',
        ]);

        $wasUsed = $this->circuits[$this->editing]['used'];
        $circuit = $publisher->publish(WorkflowSubject::Tdr, Volet::from($this->editing), $this->name, $this->steps, auth()->user());

        Flux::toast($wasUsed
            ? "Version {$circuit->version} publiée. Les dossiers déjà soumis terminent sur l'ancienne version."
            : 'Circuit enregistré.', variant: 'success');

        $this->cancel();
        unset($this->circuits);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Circuits de validation</flux:heading>
        <flux:text class="mt-1 max-w-3xl">
            Étapes que suit un TDR après sa soumission, pour chaque volet. Une modification ne touche jamais les dossiers déjà soumis :
            ils terminent sur la version qu'ils ont commencée.
        </flux:text>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach ($this->circuits as $volet => $data)
            <flux:card wire:key="circuit-{{ $volet }}" class="space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:heading size="lg">Volet {{ $data['volet']->label() }}</flux:heading>
                            @if ($data['active'])
                                <flux:badge size="sm">v{{ $data['active']->version }}</flux:badge>
                            @endif
                        </div>
                        <flux:text size="sm">{{ $data['active']?->name ?? 'Aucun circuit : les TDR de ce volet ne peuvent pas être soumis.' }}</flux:text>
                    </div>
                    @if ($editing !== $volet)
                        <flux:button size="sm" icon="pencil-square" wire:click="edit('{{ $volet }}')">Modifier</flux:button>
                    @endif
                </div>

                @if ($editing === $volet)
                    <form wire:submit="save" class="space-y-4">
                        @if ($data['used'])
                            <flux:callout icon="information-circle" color="blue">
                                <flux:callout.text>
                                    Ce circuit est déjà utilisé{{ $data['inFlight'] ? ' ('.$data['inFlight'].' dossier(s) en cours)' : '' }}.
                                    Enregistrer publiera la version {{ $data['active']->version + 1 }} pour les prochains dossiers.
                                </flux:callout.text>
                            </flux:callout>
                        @endif

                        <flux:input wire:model="name" label="Nom du circuit" required />

                        <div class="space-y-3">
                            @foreach ($steps as $i => $step)
                                <div wire:key="step-{{ $volet }}-{{ $i }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                    <div class="mb-3 flex items-center gap-2">
                                        <span class="flex size-6 items-center justify-center rounded-full bg-prseb-navy-600 text-xs font-semibold text-white">{{ $i + 1 }}</span>
                                        <div class="flex-1">
                                            <flux:input wire:model="steps.{{ $i }}.label" placeholder="Libellé de l'étape (ex. Comité technique)" size="sm" />
                                        </div>
                                        <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="move({{ $i }}, -1)" :disabled="$i === 0" aria-label="Monter" />
                                        <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="move({{ $i }}, 1)" :disabled="$i === count($steps) - 1" aria-label="Descendre" />
                                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeStep({{ $i }})" aria-label="Supprimer l'étape" />
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <flux:select wire:model="steps.{{ $i }}.role" label="Rôle valideur" size="sm">
                                            @foreach (Role::cases() as $role)
                                                @continue(in_array($role, [Role::Admin, Role::GestionnaireParc, Role::Lecteur, Role::PointFocal], true))
                                                <flux:select.option :value="$role->value">{{ $role->label() }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:select wire:model="steps.{{ $i }}.scope" label="Périmètre" size="sm">
                                            @foreach (StepScope::cases() as $scope)
                                                <flux:select.option :value="$scope->value">{{ $scope->label() }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    </div>
                                    <flux:checkbox wire:model="steps.{{ $i }}.gives_opinion" label="Donne un avis « accordé / injonction »" class="mt-3" />
                                    <flux:error name="steps.{{ $i }}.label" />
                                </div>
                            @endforeach
                            <flux:error name="steps" />
                        </div>

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <flux:button size="sm" icon="plus" wire:click="addStep">Ajouter une étape</flux:button>
                            <div class="flex gap-2">
                                <flux:button variant="ghost" wire:click="cancel">Annuler</flux:button>
                                <flux:button type="submit" variant="primary">{{ $data['used'] ? 'Publier la v'.($data['active']->version + 1) : 'Enregistrer' }}</flux:button>
                            </div>
                        </div>
                    </form>
                @elseif ($data['active'])
                    <ol class="space-y-2">
                        @foreach ($data['active']->steps as $step)
                            <li class="flex items-center gap-3 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-700/40">
                                <span class="flex size-6 items-center justify-center rounded-full bg-prseb-navy-600 text-xs font-semibold text-white">{{ $step->position }}</span>
                                <div class="flex-1">
                                    <div class="text-sm font-medium">{{ $step->label }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $step->role->label() }} · {{ $step->scope->label() }}</div>
                                </div>
                                @if ($step->gives_opinion)
                                    <flux:badge size="sm" color="orange">Avis</flux:badge>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                    @if ($data['inFlight'])
                        <flux:text size="sm">{{ $data['inFlight'] }} dossier(s) en cours sur cette version.</flux:text>
                    @endif
                @endif

                @if ($data['history']->isNotEmpty())
                    <details class="text-sm">
                        <summary class="cursor-pointer text-zinc-500 dark:text-zinc-400">Versions précédentes ({{ $data['history']->count() }})</summary>
                        <ul class="mt-2 space-y-1">
                            @foreach ($data['history'] as $old)
                                <li class="text-zinc-600 dark:text-zinc-300">
                                    v{{ $old->version }} · {{ $old->created_at->translatedFormat('j M Y') }} :
                                    {{ $old->steps->pluck('label')->implode(' → ') }}
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </flux:card>
        @endforeach
    </div>
</div>
