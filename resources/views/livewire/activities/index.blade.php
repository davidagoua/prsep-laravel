<?php

use App\Enums\TdrState;
use App\Enums\Volet;
use App\Models\Activity;
use App\Models\Direction;
use App\Support\CurrentExercice;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Activités')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $volet = '';

    #[Url]
    public string $direction = '';

    #[Url]
    public string $tdr = '';

    public string $label = '';
    public string $code = '';
    public string $newVolet = 'projet';
    public ?string $newDirection = null;
    public string $starts_on = '';
    public string $ends_on = '';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'volet', 'direction', 'tdr'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function activities()
    {
        $user = auth()->user();

        return Activity::query()
            ->visibleTo($user)
            ->where('exercice_id', app(CurrentExercice::class)->id())
            ->with(['direction', 'tdr.currentStep'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('label', "%{$this->search}%")
                ->orWhereLike('code', "%{$this->search}%")))
            ->when($this->volet, fn ($q) => $q->where('volet', $this->volet))
            ->when($this->direction && $user->seesAllDirections(), fn ($q) => $q->where('direction_id', $this->direction))
            ->when($this->tdr === 'none', fn ($q) => $q->whereDoesntHave('tdrs', fn ($q) => $q->where('state', '!=', TdrState::Abandoned)))
            ->when($this->tdr && $this->tdr !== 'none', fn ($q) => $q->whereHas('tdrs', fn ($q) => $q->where('state', $this->tdr)))
            ->orderBy('code')
            ->orderBy('id')
            ->paginate(25);
    }

    #[Computed]
    public function directions()
    {
        return Direction::active()->orderBy('code')->get(['id', 'code']);
    }

    public function create(): void
    {
        $this->authorize('create', Activity::class);
        $this->reset('label', 'code', 'newVolet', 'newDirection', 'starts_on', 'ends_on');
        $this->resetValidation();
        Flux::modal('activity-form')->show();
    }

    public function save(): void
    {
        $this->authorize('create', Activity::class);
        $user = auth()->user();
        $exercice = app(CurrentExercice::class)->get();

        $validated = $this->validate([
            'label' => ['required', 'string', 'max:1000'],
            'code' => ['nullable', 'string', 'max:50'],
            'newVolet' => ['required', \Illuminate\Validation\Rule::enum(Volet::class)],
            'newDirection' => [$user->isAdmin() ? 'required' : 'nullable', 'nullable', 'exists:directions,id'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ], attributes: ['label' => 'libellé', 'newVolet' => 'volet', 'newDirection' => 'direction', 'starts_on' => 'début', 'ends_on' => 'fin']);

        $activity = Activity::create([
            'label' => $validated['label'],
            'code' => $validated['code'] ?: null,
            'volet' => $validated['newVolet'],
            // Un point focal crée toujours dans sa propre direction.
            'direction_id' => $user->isAdmin() ? $validated['newDirection'] : $user->direction_id,
            'focal_user_id' => $user->isAdmin() ? null : $user->id,
            'exercice_id' => $exercice->id,
            'starts_on' => $validated['starts_on'] ?: null,
            'ends_on' => $validated['ends_on'] ?: null,
        ]);

        Flux::modal('activity-form')->close();
        $this->redirectRoute('activities.show', $activity, navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Activités</flux:heading>
            <flux:text class="mt-1">
                Activités de l'exercice en cours{{ auth()->user()->seesAllDirections() ? '' : ' pour votre direction' }}.
                La hiérarchie du PTBA, le budget et l'import Excel arrivent au lot 1.
            </flux:text>
        </div>
        @can('create', App\Models\Activity::class)
            <flux:button variant="primary" icon="plus" wire:click="create">Nouvelle activité</flux:button>
        @endcan
    </div>

    <div class="grid gap-3 sm:flex sm:flex-wrap">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Rechercher un code ou un libellé" class="sm:max-w-xs" clearable />
        <flux:select wire:model.live="volet" class="sm:max-w-40">
            <flux:select.option value="">Tous les volets</flux:select.option>
            @foreach (Volet::cases() as $v)
                <flux:select.option :value="$v->value">{{ $v->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        @if (auth()->user()->seesAllDirections())
            <flux:select wire:model.live="direction" class="sm:max-w-48">
                <flux:select.option value="">Toutes les directions</flux:select.option>
                @foreach ($this->directions as $dir)
                    <flux:select.option :value="$dir->id">{{ $dir->code }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
        <flux:select wire:model.live="tdr" class="sm:max-w-48">
            <flux:select.option value="">Tous les TDR</flux:select.option>
            <flux:select.option value="none">Sans TDR</flux:select.option>
            @foreach (TdrState::cases() as $state)
                @continue($state === TdrState::Abandoned)
                <flux:select.option :value="$state->value">{{ $state->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->activities">
        <flux:table.columns>
            <flux:table.column>Activité</flux:table.column>
            <flux:table.column>Direction</flux:table.column>
            <flux:table.column class="max-sm:hidden">Période</flux:table.column>
            <flux:table.column>TDR</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->activities as $activity)
                <flux:table.row :key="$activity->id">
                    <flux:table.cell class="max-w-xl whitespace-normal!">
                        <a href="{{ route('activities.show', $activity) }}" wire:navigate class="group block">
                            <div class="flex items-center gap-2">
                                @if ($activity->code)
                                    <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $activity->code }}</span>
                                @endif
                                <flux:badge size="sm" :color="$activity->volet->color()" variant="outline">{{ $activity->volet->label() }}</flux:badge>
                            </div>
                            <div class="mt-0.5 font-medium text-zinc-800 group-hover:text-prseb-navy-600 group-hover:underline dark:text-white dark:group-hover:text-prseb-navy-300">{{ $activity->label }}</div>
                        </a>
                    </flux:table.cell>
                    <flux:table.cell>{{ $activity->direction?->code ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap text-sm max-sm:hidden">
                        @if ($activity->starts_on)
                            {{ $activity->starts_on->translatedFormat('M Y') }} → {{ $activity->ends_on?->translatedFormat('M Y') ?? '…' }}
                        @else
                            —
                        @endif
                    </flux:table.cell>
                    <flux:table.cell><x-tdr.state :tdr="$activity->tdr" /></flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="py-10 text-center">
                        Aucune activité ne correspond à ces filtres pour cet exercice.
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="activity-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">Nouvelle activité</flux:heading>
            <flux:text size="sm">Saisie ponctuelle. Les activités du PTBA seront importées depuis Excel au lot 1.</flux:text>
            <flux:textarea wire:model="label" label="Libellé" rows="2" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="code" label="Code" placeholder="A1.1.01" />
                <flux:select wire:model="newVolet" label="Volet">
                    @foreach (Volet::cases() as $v)
                        <flux:select.option :value="$v->value">{{ $v->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            @if (auth()->user()->isAdmin())
                <flux:select wire:model="newDirection" label="Direction responsable" placeholder="Choisir">
                    @foreach ($this->directions as $dir)
                        <flux:select.option :value="$dir->id">{{ $dir->code }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="starts_on" type="date" label="Début" />
                <flux:input wire:model="ends_on" type="date" label="Fin" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Créer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
