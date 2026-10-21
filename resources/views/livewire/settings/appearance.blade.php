<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Apparence" subheading="Thème de l'interface">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">Clair</flux:radio>
            <flux:radio value="dark" icon="moon">Sombre</flux:radio>
            <flux:radio value="system" icon="computer-desktop">Système</flux:radio>
        </flux:radio.group>
    </x-settings.layout>
</div>
