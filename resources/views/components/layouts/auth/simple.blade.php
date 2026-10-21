<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-prseb-navy-50 antialiased dark:bg-linear-to-b dark:from-prseb-navy-950 dark:to-neutral-900">
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="rounded-2xl bg-white p-2">
                        <img src="/images/logo-prseb.png" alt="Logo du projet" class="h-28 w-auto">
                    </span>
                    <span class="text-lg font-semibold text-prseb-navy-600 dark:text-prseb-navy-300">SIGPRO</span>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Planification, suivi et rapportage du PRSEP</span>
                </a>
                <div class="flex flex-col gap-6">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
