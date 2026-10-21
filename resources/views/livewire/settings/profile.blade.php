<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $phone = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->phone = Auth::user()->phone ?? '';
    }

    /**
     * L'adresse e-mail, la direction et les rôles sont gérés par l'administrateur.
     */
    public function updateProfileInformation(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        Auth::user()->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?: null,
        ]);

        $this->dispatch('profile-updated', name: $validated['name']);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout heading="Profil" subheading="Vos informations personnelles">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" label="Nom et prénoms" type="text" required autofocus autocomplete="name" />

            <flux:input wire:model="phone" label="Téléphone" type="tel" autocomplete="tel" />

            <flux:input :value="auth()->user()->email" label="Adresse e-mail" disabled
                description="Pour la modifier, contactez l'administrateur." />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input :value="auth()->user()->direction?->code ?? '—'" label="Direction" disabled />
                <flux:input :value="implode(', ', auth()->user()->roleLabels()) ?: '—'" label="Rôles" disabled />
            </div>

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">Enregistrer</flux:button>

                <x-action-message class="me-3" on="profile-updated">
                    Enregistré.
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
