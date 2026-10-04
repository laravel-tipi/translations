<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tipi\Translations\Contracts\TranslationModelContract;

/**
 * @mixin Model
 */
trait HasDedicatedTableTranslations
{
    use HasLocalizedTranslations;

    /**
     * @return class-string<Model&TranslationModelContract>
     */
    public static function getTranslationModelClass(): string
    {
        return static::$translationModel ?? static::class.'Translation';
    }

    public function translationRecords(): HasMany
    {
        return $this->hasMany(
            static::getTranslationModelClass(),
        );
    }
}
