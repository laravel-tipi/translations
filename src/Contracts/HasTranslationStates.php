<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface HasTranslationStates
{
    public function translationStates(): MorphMany;
}
