<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Tipi\Translations\Config\TranslationConfig;

trait InteractsWithTranslationStates
{
    public function translationStates(): MorphMany
    {
        return $this->morphMany(
            related: resolve(TranslationConfig::class)->translationStateModel,
            name: 'translatable',
        );
    }
}
