<?php

use App\Enums\Role;
use App\Models\Direction;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Utilisateurs')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Url]
    public string $direction = '';

    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public ?string $direction_id = null;
    /** @var list<string> */
    public array $roles = [];
    public bool $is_active = true;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'role', 'direction'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->with(['direction', 'roles'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('name', "%{$this->search}%")
                ->orWhereLike('email', "%{$this->search}%")))
            ->when($this->role, fn ($q) => $q->role($this->role))
            ->when($this->direction, fn ($q) => $q->where('direction_id', $this->direction))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(20);
    }

    #[Computed]
    public function directions()
    {
        return Direction::active()->orderBy('code')->get(['id', 'code', 'name']);
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('user-form')->show();
    }

    public function edit(User $user): void
    {
        $this->resetForm();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone ?? '';
        $this->direction_id = $user->direction_id ? (string) $user->direction_id : null;
        $this->roles = $user->roles->pluck('name')->all();
        $this->is_active = $user->is_active;

        Flux::modal('user-form')->show();
    }

    public function save(): void
    {
        $needsDirection = collect($this->roles)->contains(fn ($r) => Role::tryFrom($r)?->isScopedToDirection());

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'phone' => ['nullable', 'string', 'max:30'],
            'direction_id' => [Rule::requiredIf($needsDirection), 'nullable', 'exists:directions,id'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(Role::class)],
            'is_active' => ['boolean'],
        ], [
            'direction_id.required' => 'La direction est obligatoire pour un point focal ou un directeur central.',
            'roles.required' => 'Attribuez au moins un rôle.',
        ], [
            'name' => 'nom', 'email' => 'adresse e-mail', 'direction_id' => 'direction', 'roles' => 'rôles',
        ]);

        if ($this->editingId === auth()->id()) {
            // Un administrateur ne peut pas se retirer ses propres droits.
            $validated['is_active'] = true;
            $validated['roles'] = array_values(array_unique([...$validated['roles'], Role::Admin->value]));
        }

        $attributes = [
            'name' => $validated['name'],
            'email' => Str::lower($validated['email']),
            'phone' => $validated['phone'] ?: null,
            'direction_id' => $validated['direction_id'],
            'is_active' => $validated['is_active'],
        ];

        if ($this->editingId) {
            $user = User::findOrFail($this->editingId);
            $user->update($attributes);
            $message = 'Compte mis à jour.';
        } else {
            $user = User::create($attributes + ['password' => Str::password(32)]);
            Password::sendResetLink(['email' => $user->email]);
            $message = 'Compte créé. Une invitation à choisir un mot de passe a été envoyée.';
        }

        $user->syncRoles($validated['roles']);

        Flux::modal('user-form')->close();
        Flux::toast($message, variant: 'success');
        $this->resetForm();
    }

    public function sendInvitation(User $user): void
    {
        Password::sendResetLink(['email' => $user->email]);
        Flux::toast("Lien envoyé à {$user->email}.", variant: 'success');
    }

    public function toggleActive(User $user): void
    {
        if ($user->is(auth()->user())) {
            Flux::toast('Vous ne pouvez pas désactiver votre propre compte.', variant: 'danger');

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);
        Flux::toast($user->is_active ? 'Compte réactivé.' : 'Compte désactivé.', variant: 'success');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'email', 'phone', 'direction_id', 'roles', 'is_active');
        $this->resetValidation();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Utilisateurs</flux:heading>
            <flux:text class="mt-1">Comptes, directions et rôles. Un nouvel utilisateur reçoit un e-mail pour choisir son mot de passe.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">Nouvel utilisateur</flux:button>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Rechercher un nom ou un e-mail" class="max-w-xs" clearable />
        <flux:select wire:model.live="role" class="max-w-52">
            <flux:select.option value="">Tous les rôles</flux:select.option>
            @foreach (Role::options() as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="direction" class="max-w-52">
            <flux:select.option value="">Toutes les directions</flux:select.option>
            @foreach ($this->directions as $dir)
                <flux:select.option :value="$dir->id">{{ $dir->code }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <flux:table :paginate="$this->users">
        <flux:table.columns>
            <flux:table.column>Nom</flux:table.column>
            <flux:table.column>Direction</flux:table.column>
            <flux:table.column>Rôles</flux:table.column>
            <flux:table.column>Dernière connexion</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->users as $user)
                <flux:table.row :key="$user->id" @class(['opacity-60' => ! $user->is_active])>
                    <flux:table.cell>
                        <div class="font-medium text-zinc-800 dark:text-white">{{ $user->name }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</div>
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->direction?->code ?? '—' }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex flex-wrap gap-1">
                            @foreach ($user->roleLabels() as $label)
                                <flux:badge size="sm">{{ $label }}</flux:badge>
                            @endforeach
                            @unless ($user->is_active)
                                <flux:badge size="sm" color="red">Désactivé</flux:badge>
                            @endunless
                        </div>
                    </flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">
                        {{ $user->last_login_at?->diffForHumans() ?? 'Jamais' }}
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions" />
                            <flux:menu>
                                <flux:menu.item icon="pencil-square" wire:click="edit({{ $user->id }})">Modifier</flux:menu.item>
                                <flux:menu.item icon="envelope" wire:click="sendInvitation({{ $user->id }})">Renvoyer le lien de mot de passe</flux:menu.item>
                                @unless ($user->is(auth()->user()))
                                    <flux:menu.separator />
                                    <flux:menu.item :icon="$user->is_active ? 'no-symbol' : 'check-circle'"
                                        :variant="$user->is_active ? 'danger' : null"
                                        wire:click="toggleActive({{ $user->id }})">
                                        {{ $user->is_active ? 'Désactiver' : 'Réactiver' }}
                                    </flux:menu.item>
                                @endunless
                            </flux:menu>
                        </flux:dropdown>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center">Aucun utilisateur ne correspond à ces filtres.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="user-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Modifier le compte' : 'Nouvel utilisateur' }}</flux:heading>

            <flux:input wire:model="name" label="Nom et prénoms" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="email" type="email" label="Adresse e-mail" required />
                <flux:input wire:model="phone" type="tel" label="Téléphone" />
            </div>

            <flux:checkbox.group wire:model.live="roles" label="Rôles">
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (Role::cases() as $r)
                        <flux:checkbox :value="$r->value" :label="$r->label()" />
                    @endforeach
                </div>
            </flux:checkbox.group>

            <flux:select wire:model="direction_id" label="Direction" placeholder="Choisir une direction"
                :description="collect($roles)->contains(fn ($r) => Role::tryFrom($r)?->isScopedToDirection()) ? 'Obligatoire : ce rôle ne voit que les données de sa direction.' : 'Facultatif pour ces rôles.'">
                <flux:select.option value="">—</flux:select.option>
                @foreach ($this->directions as $dir)
                    <flux:select.option :value="$dir->id">{{ $dir->code }}{{ $dir->name !== $dir->code ? ' — '.$dir->name : '' }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($editingId && $editingId !== auth()->id())
                <flux:switch wire:model="is_active" label="Compte actif" description="Un compte désactivé ne peut plus se connecter." />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Annuler</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId ? 'Enregistrer' : 'Créer et inviter' }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
