<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $email = '';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($this->only('email'));

        session()->flash('status', 'Si un compte existe pour cette adresse, un lien de réinitialisation vient d\'être envoyé.');
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header title="Mot de passe oublié" description="Saisissez votre adresse e-mail pour recevoir un lien de réinitialisation" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-6">
        <!-- Email Address -->
        <div class="grid gap-2">
            <flux:input wire:model="email" label="Adresse e-mail" type="email" name="email" required autofocus placeholder="prenom.nom@education.gouv.ci" />
        </div>

        <flux:button variant="primary" type="submit" class="w-full">Recevoir le lien</flux:button>
    </form>

    <div class="space-x-1 text-center text-sm text-zinc-400">
        Retour à la
        <x-text-link href="{{ route('login') }}">connexion</x-text-link>
    </div>
</div>
