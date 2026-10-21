<?php

use App\Support\Inbox;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('À traiter')] class extends Component {
    #[Computed]
    public function sections(): array
    {
        return app(Inbox::class)->sections(auth()->user());
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">À traiter</flux:heading>
        <flux:text class="mt-1">Tout ce qui attend une action de votre part, du plus ancien au plus récent.</flux:text>
    </div>

    @forelse ($this->sections as $section)
        <x-inbox.section :section="$section" />
    @empty
        <flux:card class="flex flex-col items-center gap-2 py-12 text-center">
            <flux:icon.check-badge class="size-12 text-prseb-green-600" />
            <flux:heading size="lg">Rien à traiter</flux:heading>
            <flux:text>Vous serez notifié dès qu'un dossier aura besoin de vous.</flux:text>
        </flux:card>
    @endforelse
</div>
