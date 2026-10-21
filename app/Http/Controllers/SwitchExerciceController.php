<?php

namespace App\Http\Controllers;

use App\Models\Exercice;
use App\Support\CurrentExercice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchExerciceController extends Controller
{
    public function __invoke(Request $request, CurrentExercice $current): RedirectResponse
    {
        $exercice = Exercice::findOrFail($request->validate([
            'exercice_id' => ['required', 'integer'],
        ])['exercice_id']);

        $current->set($exercice);

        return back()->with('status', "Vous travaillez désormais sur l'{$exercice->label}.");
    }
}
