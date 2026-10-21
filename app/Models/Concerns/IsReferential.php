<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Référentiel à libellé unique (catégories, unités, UGP, sources de financement).
 */
trait IsReferential
{
    public function initializeIsReferential(): void
    {
        $this->mergeFillable(['label']);
    }

    /** Retrouve (ou crée) l'entrée par son libellé, sans tenir compte de la casse ni des espaces superflus. */
    public static function resolve(?string $label): ?static
    {
        $label = Str::of((string) $label)->squish()->toString();
        if ($label === '') {
            return null;
        }

        return static::query()->whereRaw('lower(label) = ?', [Str::lower($label)])->first()
            ?? static::create(['label' => $label]);
    }
}
