<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Exceptions\InvalidTranslationConfigurationException;
use Tipi\Translations\TranslationManager;

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

    public function getAttribute($key): mixed
    {
        if (
            is_string($key)
            && in_array($key, static::getTranslatableAttributes(), true)
        ) {
            return $this->translated($key);
        }

        return parent::getAttribute($key);
    }

    public function translated(
        string $attribute,
        mixed $default = null,
        ?string $localeCode = null,
    ): mixed {
        $localeCode ??= resolve(LocaleProvider::class)->current()->code;

        $translation = resolve(TranslationManager::class)->get(
            translatable: $this,
            localeCode: $localeCode,
        );

        return data_get(
            target: $translation?->attributes,
            key: $attribute,
            default: $default,
        );
    }
}
