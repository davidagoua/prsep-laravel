<?php

use App\Http\Controllers\WopiController;
use Illuminate\Support\Facades\Route;

// Appelé par Collabora : pas de session ni de CSRF, accès par jeton signé (?access_token=).
Route::get('files/{fileId}', [WopiController::class, 'info'])->name('wopi.info');
Route::get('files/{fileId}/contents', [WopiController::class, 'contents'])->name('wopi.contents');
Route::post('files/{fileId}/contents', [WopiController::class, 'save'])->name('wopi.save');
Route::post('files/{fileId}', [WopiController::class, 'operation'])->name('wopi.operation');
