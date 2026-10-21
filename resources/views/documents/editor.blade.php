<!DOCTYPE html>
<html lang="fr" class="h-full">
    <head>
        @include('partials.head', ['title' => $file->title()])
    </head>
    <body class="flex h-full flex-col bg-zinc-50 dark:bg-zinc-900">
        <header class="flex items-center gap-3 border-b border-prseb-navy-800 bg-prseb-navy-900 px-4 py-2 text-white">
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="$file->backUrl()" class="text-white! hover:bg-white/10!">Retour</flux:button>
            <div class="min-w-0 flex-1">
                <div class="truncate text-sm font-semibold">{{ $file->title() }}</div>
                <div class="truncate text-xs text-prseb-navy-200">{{ $file->name() }} · {{ $write ? 'modification en ligne, enregistrement automatique' : 'lecture seule' }}</div>
            </div>
        </header>

        @if ($action)
            <form id="collabora-form" action="{{ $action }}" method="post" target="collabora-frame" class="hidden">
                <input type="hidden" name="access_token" value="{{ $token['token'] }}">
                <input type="hidden" name="access_token_ttl" value="{{ $token['expires_ms'] }}">
            </form>
            <iframe name="collabora-frame" title="Éditeur de document" class="w-full flex-1 border-0" allow="clipboard-read; clipboard-write; fullscreen"></iframe>
            <script>document.getElementById('collabora-form').submit();</script>
        @else
            <div class="m-auto max-w-md p-6 text-center">
                <flux:icon.exclamation-triangle class="mx-auto size-10 text-prseb-orange-500" />
                <flux:heading size="lg" class="mt-2">Édition en ligne indisponible</flux:heading>
                <flux:text class="mt-1">Collabora Online ne répond pas ou ne prend pas en charge ce format. Téléchargez le fichier pour le consulter.</flux:text>
                <flux:button class="mt-4" :href="$file->backUrl()">Retour</flux:button>
            </div>
        @endif

        @fluxScripts
    </body>
</html>
