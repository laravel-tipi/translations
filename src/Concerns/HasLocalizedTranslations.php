<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Exceptions\InvalidTranslationConfigurationException;

/**
 * @mixin Model
 */
trait HasLocalizedTranslations
{
    /**
     * @return array<int, string>
     */
    public static function getTranslatableAttributes(): array
    {
        if (
            ! isset(static::$translatableAttributes) ||
            static::$translatableAttributes === []
        ) {
            throw InvalidTranslationConfigurationException::missing(
                model: static::class,
                configuration: 'translatableAttributes',
            );
        }

        return static::$translatableAttributes;
    }
}
