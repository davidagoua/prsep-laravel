<?php

use App\Http\Controllers\SwitchExerciceController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/accueil')->name('home');

Route::middleware(['auth'])->group(function () {
    Volt::route('accueil', 'dashboard')->name('dashboard');

    Route::post('exercice', SwitchExerciceController::class)->name('exercice.switch');

    Route::redirect('settings', 'settings/profile');
    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('utilisateurs', 'admin.users')->name('users');
        Volt::route('directions', 'admin.directions')->name('directions');
        Volt::route('exercices', 'admin.exercices')->name('exercices');
    });
});

require __DIR__.'/auth.php';
