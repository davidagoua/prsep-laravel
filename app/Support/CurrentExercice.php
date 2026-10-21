<?php

namespace App\Support;

use App\Models\Exercice;
use Illuminate\Contracts\Session\Session;

/**
 * Exercice sur lequel travaille l'utilisateur : son choix en session,
 * sinon l'exercice courant défini par l'administrateur.
 */
class CurrentExercice
{
    private const SESSION_KEY = 'exercice_id';

    private ?Exercice $resolved = null;

    private bool $loaded = false;

    public function __construct(private readonly Session $session) {}

    public function get(): ?Exercice
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $id = $this->session->get(self::SESSION_KEY);
            $this->resolved = ($id ? Exercice::find($id) : null) ?? Exercice::current();
        }

        return $this->resolved;
    }

    public function id(): ?int
    {
        return $this->get()?->id;
    }

    public function set(Exercice $exercice): void
    {
        $this->session->put(self::SESSION_KEY, $exercice->id);
        $this->resolved = $exercice;
        $this->loaded = true;
    }
}
