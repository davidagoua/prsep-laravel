<?php

use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    /** Écoute en temps réel (Reverb) les notifications de l'utilisateur. */
    public function getListeners(): array
    {
        return [
            'echo-private:App.Models.User.'.auth()->id().',.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated' => 'received',
        ];
    }

    public function received(array $payload): void
    {
        unset($this->notifications, $this->unreadCount);
        Flux::toast($payload['message'] ?? '', heading: $payload['title'] ?? 'Notification');
    }

    #[Computed]
    public function notifications()
    {
        return auth()->user()->notifications()->latest()->limit(8)->get();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return auth()->user()->unreadNotifications()->count();
    }

    public function open(string $id): void
    {
        $notification = auth()->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $this->redirect($notification->data['url'] ?? route('inbox'), navigate: true);
    }

    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
        unset($this->notifications, $this->unreadCount);
    }
}; ?>

<div>
    <flux:dropdown position="bottom" align="end">
        <button type="button" class="relative flex size-9 items-center justify-center rounded-lg text-prseb-navy-100 hover:bg-white/10 hover:text-white"
            aria-label="Notifications{{ $this->unreadCount ? ' ('.$this->unreadCount.' non lues)' : '' }}">
            <flux:icon.bell class="size-5" />
            @if ($this->unreadCount)
                <span class="absolute -end-0.5 -top-0.5 flex min-w-4 items-center justify-center rounded-full bg-prseb-orange-500 px-1 text-[10px] font-bold leading-4 text-white">
                    {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
                </span>
            @endif
        </button>

        <flux:menu class="w-80 max-w-[calc(100vw-2rem)]">
            <div class="flex items-center justify-between px-2 py-1.5">
                <span class="text-sm font-semibold">Notifications</span>
                @if ($this->unreadCount)
                    <button type="button" wire:click="markAllAsRead" class="text-xs text-prseb-navy-600 hover:underline dark:text-prseb-navy-300">Tout marquer comme lu</button>
                @endif
            </div>
            <flux:menu.separator />
            @forelse ($this->notifications as $notification)
                <button type="button" wire:key="notif-{{ $notification->id }}" wire:click="open('{{ $notification->id }}')"
                    class="flex w-full gap-2 rounded-md px-2 py-2 text-start hover:bg-zinc-100 dark:hover:bg-zinc-700">
                    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-prseb-orange-500' => ! $notification->read_at, 'bg-transparent' => $notification->read_at])></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">{{ $notification->data['title'] ?? 'Notification' }}</span>
                        <span class="block text-xs text-zinc-600 dark:text-zinc-300">{{ $notification->data['message'] ?? '' }}</span>
                        <span class="block text-xs text-zinc-400">{{ $notification->created_at->diffForHumans() }}</span>
                    </span>
                </button>
            @empty
                <div class="px-2 py-6 text-center text-sm text-zinc-500">Aucune notification.</div>
            @endforelse
        </flux:menu>
    </flux:dropdown>
</div>
