<?php

use App\Models\Direction;
use App\Models\User;
use App\Support\CurrentExercice;
use App\Support\Inbox;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Accueil')] class extends Component {
    #[Computed]
    public function exercice()
    {
        return app(CurrentExercice::class)->get();
    }

    #[Computed]
    public function inbox(): array
    {
        return app(Inbox::class)->sections(auth()->user());
    }

    /** Avancement de l'exercice dans le temps, en pourcentage. */
    #[Computed]
    public function progress(): ?int
    {
        if (! $this->exercice) {
            return null;
        }

        $total = $this->exercice->starts_on->diffInDays($this->exercice->ends_on) + 1;
        $elapsed = $this->exercice->starts_on->diffInDays(now()->min($this->exercice->ends_on), false) + 1;

        return (int) max(0, min(100, round($elapsed / $total * 100)));
    }

    #[Computed]
    public function currentPeriodes()
    {
        return $this->exercice?->periodes()
            ->whereDate('starts_on', '<=', today())
            ->whereDate('ends_on', '>=', today())
            ->whereIn('type', ['mois', 'trimestre'])
            ->get() ?? collect();
    }

    /**
     * Étapes de mise en place restantes, visibles par l'administrateur.
     *
     * @return list<array{label: string, done: bool, route: string, hint: string}>
     */
    #[Computed]
    public function setupSteps(): array
    {
        $directionsSansIntitule = Direction::whereColumn('name', 'code')->count();
        $usersIncomplets = User::active()->whereDoesntHave('roles')->count();

        return [
            [
                'label' => 'Définir l\'exercice en cours',
                'done' => $this->exercice !== null,
                'route' => 'admin.exercices',
                'hint' => 'Toutes les données sont rattachées à un exercice.',
            ],
            [
                'label' => 'Compléter les intitulés des directions',
                'done' => $directionsSansIntitule === 0,
                'route' => 'admin.directions',
                'hint' => $directionsSansIntitule.' direction(s) n\'ont que leur sigle.',
            ],
            [
                'label' => 'Créer les comptes et attribuer les rôles',
                'done' => User::count() > 1 && $usersIncomplets === 0,
                'route' => 'admin.users',
                'hint' => $usersIncomplets > 0 ? $usersIncomplets.' compte(s) sans rôle.' : 'Points focaux, directeurs, coordination…',
            ],
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Bonjour {{ str(auth()->user()->name)->before(' ') }}</flux:heading>
        <flux:text class="mt-1">
            {{ implode(' · ', array_filter([auth()->user()->direction?->code, ...auth()->user()->roleLabels()])) }}
        </flux:text>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Exercice de travail --}}
        <flux:card class="lg:col-span-1">
            <flux:subheading>Exercice de travail</flux:subheading>
            @if ($this->exercice)
                <flux:heading size="lg" class="mt-1">{{ $this->exercice->label }}</flux:heading>
                <flux:text class="mt-1">
                    Du {{ $this->exercice->starts_on->translatedFormat('j F Y') }}
                    au {{ $this->exercice->ends_on->translatedFormat('j F Y') }}
                </flux:text>

                <div class="mt-4">
                    <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span>Temps écoulé</span>
                        <span>{{ $this->progress }} %</span>
                    </div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div class="h-full rounded-full bg-accent" style="width: {{ $this->progress }}%"></div>
                    </div>
                </div>

                @if ($this->currentPeriodes->isNotEmpty())
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ($this->currentPeriodes as $periode)
                            <flux:badge size="sm">{{ $periode->label }}</flux:badge>
                        @endforeach
                    </div>
                @endif
            @else
                <flux:text class="mt-2">Aucun exercice n'est ouvert.</flux:text>
            @endif
        </flux:card>

        {{-- À traiter --}}
        <div class="space-y-4 lg:col-span-2">
            @forelse (array_slice($this->inbox, 0, 2) as $section)
                <x-inbox.section :section="$section" :limit="4" />
            @empty
                <flux:card class="flex h-full flex-col items-center justify-center gap-2 py-10 text-center">
                    <flux:icon.check-badge class="size-10 text-prseb-green-600" />
                    <flux:heading>Rien à traiter</flux:heading>
                    <flux:text size="sm" class="max-w-md">Les TDR à valider ou à corriger et les activités à préparer apparaîtront ici.</flux:text>
                </flux:card>
            @endforelse
            @if (count($this->inbox) > 2)
                <flux:button :href="route('inbox')" wire:navigate variant="ghost" icon-trailing="arrow-right" class="w-full">Tout ce qui est à traiter</flux:button>
            @endif
        </div>
    </div>

    @if (auth()->user()->isAdmin())
        <flux:card>
            <flux:heading size="lg">Mise en place</flux:heading>
            <flux:text class="mt-1">Les étapes à terminer avant d'ouvrir l'application aux directions.</flux:text>

            <ul class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->setupSteps as $step)
                    <li class="flex items-center gap-3 py-3">
                        @if ($step['done'])
                            <flux:icon.check-circle variant="solid" class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                        @else
                            <flux:icon.ellipsis-horizontal-circle class="size-5 shrink-0 text-zinc-400" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium {{ $step['done'] ? 'text-zinc-500 line-through dark:text-zinc-400' : '' }}">{{ $step['label'] }}</div>
                            @unless ($step['done'])
                                <flux:text size="sm">{{ $step['hint'] }}</flux:text>
                            @endunless
                        </div>
                        @unless ($step['done'])
                            <flux:button size="sm" :href="route($step['route'])" wire:navigate>Ouvrir</flux:button>
                        @endunless
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endif
</div>
