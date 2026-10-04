<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait HasSharedTableTranslations
{
    use HasLocalizedTranslations;

    public function translationRecords(): MorphMany
    {
        return $this->morphMany(
            static::getTranslationModelClass(),
        );
    }
}
