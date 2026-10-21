<?php

use App\Http\Controllers\FinanceExportController;
use App\Http\Controllers\PtbaFileController;
use App\Http\Controllers\SwitchExerciceController;
use App\Http\Controllers\TdrDocumentController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/accueil')->name('home');

Route::middleware(['auth'])->group(function () {
    Volt::route('accueil', 'dashboard')->name('dashboard');

    Route::post('exercice', SwitchExerciceController::class)->name('exercice.switch');

    Volt::route('a-traiter', 'inbox')->name('inbox');
    Volt::route('activites', 'activities.index')->name('activities.index');
    Volt::route('activites/{activity}', 'activities.show')->name('activities.show');
    Volt::route('validations', 'validations')->name('validations');
    Route::get('tdr/documents/{document}', TdrDocumentController::class)->name('tdr-documents.show');

    Volt::route('activites-arborescence', 'activities.tree')->name('activities.tree');
    Volt::route('ptba/import', 'ptba.import')->middleware('can:import-ptba')->name('ptba.import');
    Route::get('ptba/modele/{volet}', [PtbaFileController::class, 'template'])->name('ptba.template');
    Route::get('ptba/import/{import}/anomalies', [PtbaFileController::class, 'report'])->name('ptba.report');

    Route::middleware('can:view-finance')->prefix('suivi-financier')->name('finance.')->group(function () {
        Volt::route('/', 'finance.index')->name('index');
        Volt::route('drf', 'finance.drf')->name('drf');
        Route::get('export', [FinanceExportController::class, 'tree'])->name('export');
        Route::get('drf/{drf}/export', [FinanceExportController::class, 'drf'])->name('drf.export');
    });

    Route::redirect('settings', 'settings/profile');
    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Volt::route('utilisateurs', 'admin.users')->name('users');
        Volt::route('directions', 'admin.directions')->name('directions');
        Volt::route('exercices', 'admin.exercices')->name('exercices');
        Volt::route('circuits', 'admin.circuits')->name('circuits');
    });
});

require __DIR__.'/auth.php';
