<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Exceptions\InvalidTranslationConfigurationException;
use Tipi\Translations\Translation;
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
        return data_get(
            target: $this->getTranslation($localeCode)?->attributes,
            key: $attribute,
            default: $default,
        );
    }

    public function getTranslation(?string $localeCode = null): ?Translation
    {
        $localeCode ??= resolve(LocaleProvider::class)->current()->code;

        return resolve(TranslationManager::class)->get(
            translatable: $this,
            localeCode: $localeCode,
        );
    }

    /**
     * @return Collection<int, Translation>
     *
     * @throws JsonException
     */
    public function getTranslations(): Collection
    {
        return resolve(TranslationManager::class)->getAll(
            translatable: $this,
        );
    }

    /**
     * @throws JsonException
     */
    public function translationExists(?string $localeCode = null): bool
    {
        $localeCode ??= resolve(LocaleProvider::class)->current()->code;

        return resolve(TranslationManager::class)->exists(
            translatable: $this,
            localeCode: $localeCode,
        );
    }

    public function isTranslationMissing(string $localeCode): bool
    {
        return ! $this->translationExists($localeCode);
    }

    public function hasMissingTranslations(): bool
    {
        $locales = resolve(LocaleProvider::class);

        $defaultCode = $locales->default()->code;

        return $locales->supported()
            ->except($defaultCode)
            ->keys()
            ->contains(
                fn (string $localeCode): bool => $this->isTranslationMissing($localeCode),
            );
    }

    public function canBeTranslated(): bool
    {
        return $this->hasMissingTranslations();
    }
}
