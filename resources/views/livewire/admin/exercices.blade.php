<?php

use App\Enums\ExerciceStatus;
use App\Models\Exercice;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Exercices')] class extends Component {
    public ?int $editingId = null;
    public ?int $year = null;
    public string $label = '';
    public string $starts_on = '';
    public string $ends_on = '';
    public ?int $budget_total = null;

    public ?int $showingPeriodesOf = null;

    #[Computed]
    public function exercices()
    {
        return Exercice::query()->withCount('periodes')->orderByDesc('year')->get();
    }

    #[Computed]
    public function periodes()
    {
        return $this->showingPeriodesOf
            ? Exercice::find($this->showingPeriodesOf)?->periodes->groupBy(fn ($p) => $p->type->value)
            : collect();
    }

    public function create(): void
    {
        $this->resetForm();
        $next = (Exercice::max('year') ?? now()->year - 1) + 1;
        $this->fillYear($next);
        Flux::modal('exercice-form')->show();
    }

    public function edit(Exercice $exercice): void
    {
        $this->resetForm();
        $this->editingId = $exercice->id;
        $this->year = $exercice->year;
        $this->label = $exercice->label;
        $this->starts_on = $exercice->starts_on->toDateString();
        $this->ends_on = $exercice->ends_on->toDateString();
        $this->budget_total = $exercice->budget_total ?: null;
        Flux::modal('exercice-form')->show();
    }

    /** Pré-remplit libellé et dates quand l'année change (création uniquement). */
    public function updatedYear($value): void
    {
        if (! $this->editingId && is_numeric($value)) {
            $this->fillYear((int) $value);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'year' => ['required', 'integer', 'between:2000,2100', Rule::unique('exercices', 'year')->ignore($this->editingId)],
            'label' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'budget_total' => ['nullable', 'integer', 'min:0'],
        ], attributes: [
            'year' => 'année', 'label' => 'libellé', 'starts_on' => 'date de début',
            'ends_on' => 'date de fin', 'budget_total' => 'budget total',
        ]);
        $validated['budget_total'] = (int) ($validated['budget_total'] ?? 0);

        DB::transaction(function () use ($validated) {
            if ($this->editingId) {
                $exercice = Exercice::findOrFail($this->editingId);
                $datesChanged = $exercice->starts_on->toDateString() !== $validated['starts_on']
                    || $exercice->ends_on->toDateString() !== $validated['ends_on'];
                $exercice->update($validated);

                // Les périodes ne portent encore aucune donnée : on peut les régénérer.
                if ($datesChanged) {
                    $exercice->periodes()->delete();
                }
            } else {
                $exercice = Exercice::create($validated + ['status' => ExerciceStatus::EnPreparation]);
            }

            $exercice->generatePeriodes();
        });

        Flux::modal('exercice-form')->close();
        Flux::toast($this->editingId ? 'Exercice mis à jour.' : 'Exercice créé avec ses périodes.', variant: 'success');
        $this->resetForm();
    }

    public function makeCurrent(Exercice $exercice): void
    {
        if ($exercice->status === ExerciceStatus::Clos) {
            Flux::toast('Un exercice clos ne peut pas devenir l\'exercice en cours.', variant: 'danger');

            return;
        }

        $exercice->makeCurrent();
        if ($exercice->status === ExerciceStatus::EnPreparation) {
            $exercice->update(['status' => ExerciceStatus::Ouvert]);
        }
        Flux::toast("{$exercice->label} est désormais l'exercice en cours.", variant: 'success');
    }

    public function setStatus(Exercice $exercice, string $status): void
    {
        $status = ExerciceStatus::from($status);

        if ($status === ExerciceStatus::Clos && $exercice->is_current) {
            Flux::toast('Désignez d\'abord un autre exercice en cours avant de clore celui-ci.', variant: 'danger');

            return;
        }

        $exercice->update(['status' => $status]);
        Flux::toast("{$exercice->label} : {$status->label()}.", variant: 'success');
    }

    public function showPeriodes(int $id): void
    {
        $this->showingPeriodesOf = $id;
        Flux::modal('exercice-periodes')->show();
    }

    private function fillYear(int $year): void
    {
        $this->year = $year;
        $this->label = "Exercice {$year}";
        $this->starts_on = "{$year}-01-01";
        $this->ends_on = "{$year}-12-31";
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'year', 'label', 'starts_on', 'ends_on', 'budget_total');
        $this->resetValidation();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Exercices</flux:heading>
            <flux:text class="mt-1">L'exercice en cours est celui proposé par défaut. Chacun peut consulter un autre exercice depuis le menu.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">Nouvel exercice</flux:button>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Exercice</flux:table.column>
            <flux:table.column>Période</flux:table.column>
            <flux:table.column align="end">Budget (FCFA)</flux:table.column>
            <flux:table.column>Statut</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->exercices as $exercice)
                <flux:table.row :key="$exercice->id">
                    <flux:table.cell>
                        <span class="font-medium text-zinc-800 dark:text-white">{{ $exercice->label }}</span>
                        @if ($exercice->is_current)
                            <flux:badge size="sm" color="emerald" class="ms-1">En cours</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">
                        {{ $exercice->starts_on->format('d/m/Y') }} → {{ $exercice->ends_on->format('d/m/Y') }}
                    </flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">
                        {{ $exercice->budget_total ? number_format($exercice->budget_total, 0, ',', ' ') : '—' }}
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$exercice->status->color()">{{ $exercice->status->label() }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $exercice->id }})">Modifier</flux:menu.item>
                                <flux:menu.item icon="calendar" wire:click="showPeriodes({{ $exercice->id }})">Voir les périodes ({{ $exercice->periodes_count }})</flux:menu.item>
                                @unless ($exercice->is_current || $exercice->status === ExerciceStatus::Clos)
                                    <flux:menu.item icon="star" wire:click="makeCurrent({{ $exercice->id }})">Définir comme exercice en cours</flux:menu.item>
                                @endunless
                                <flux:menu.separator />
                                @if ($exercice->status === ExerciceStatus::Clos)
                                    <flux:menu.item icon="lock-open" wire:click="setStatus({{ $exercice->id }}, 'ouvert')">Rouvrir</flux:menu.item>
                                @else
                                    <flux:menu.item icon="lock-closed" variant="danger"
                                        wire:click="setStatus({{ $exercice->id }}, 'clos')"
                                        wire:confirm="Clore {{ $exercice->label }} ? Les saisies seront bloquées.">Clore l'exercice</flux:menu.item>
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center">Aucun exercice. Créez le premier pour commencer.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="exercice-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Modifier l\'exercice' : 'Nouvel exercice' }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model.live.debounce.400ms="year" type="number" label="Année" required />
                <div class="sm:col-span-2">
                    <flux:input wire:model="label" label="Libellé" required />
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="starts_on" type="date" label="Début" required />
                <flux:input wire:model="ends_on" type="date" label="Fin" required />
            </div>
            <flux:input wire:model="budget_total" type="number" min="0" label="Budget total (FCFA)" description="Facultatif. Sert au calcul du reste à planifier." />

            <flux:text size="sm">Les mois, trimestres et semestres sont générés automatiquement à partir des dates.</flux:text>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Annuler</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="exercice-periodes" class="w-full max-w-2xl">
        <flux:heading size="lg">Périodes</flux:heading>
        <div class="mt-4 grid gap-6 sm:grid-cols-2">
            @foreach (['trimestre' => 'Trimestres', 'semestre' => 'Semestres', 'mois' => 'Mois'] as $type => $title)
                @if ($this->periodes->has($type))
                    <div @class(['sm:col-span-2' => $type === 'mois'])>
                        <flux:subheading class="mb-2">{{ $title }}</flux:subheading>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->periodes[$type] as $periode)
                                <flux:badge size="sm" :title="$periode->starts_on->format('d/m').' → '.$periode->ends_on->format('d/m')">{{ $periode->label }}</flux:badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </flux:modal>
</div>
