<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait HasJsonTranslations
{
    use HasLocalizedTranslations;

    public function initializeHasJsonTranslations(): void
    {
        $this->mergeCasts(
            array_fill_keys(
                static::getTranslatableAttributes(),
                'array',
            ),
        );
    }
}
