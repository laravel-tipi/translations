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
    use HasTranslationQueries;

    /**
     * @var array<string, Translation|null>
     */
    private array $resolvedTranslations = [];

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

    //    public function translated(
    //        string $attribute,
    //        mixed $default = null,
    //        ?string $localeCode = null,
    //    ): mixed {
    //        return data_get(
    //            target: $this->getTranslation($localeCode)?->attributes,
    //            key: $attribute,
    //            default: $default,
    //        );
    //    }

    public function translated(
        string $attribute,
        mixed $default = null,
        ?string $localeCode = null,
    ): mixed {
        return $this->getTranslation($localeCode)
            ?->attributes[$attribute] ?? $default;
    }

    public function getTranslation(?string $localeCode = null): ?Translation
    {
        $localeCode ??= resolve(LocaleProvider::class)->current()->code;

        if (array_key_exists($localeCode, $this->resolvedTranslations)) {
            return $this->resolvedTranslations[$localeCode];
        }

        return $this->resolvedTranslations[$localeCode] = resolve(TranslationManager::class)->get(
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
        $translations = resolve(TranslationManager::class)->getAll(
            translatable: $this,
        );

        return $translations->map(function (Translation $translation): Translation {
            if (array_key_exists(
                $translation->localeCode,
                $this->resolvedTranslations,
            )) {
                return $this->resolvedTranslations[$translation->localeCode]
                    ?? $translation;
            }

            $this->resolvedTranslations[$translation->localeCode] = $translation;

            return $translation;
        });
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

    public function setResolvedTranslation(
        string $localeCode,
        ?Translation $translation,
    ): void {
        $this->resolvedTranslations[$localeCode] = $translation;
    }

    public function forgetResolvedTranslation(string $localeCode): void
    {
        unset($this->resolvedTranslations[$localeCode]);
    }

    public function forgetResolvedTranslations(): void
    {
        $this->resolvedTranslations = [];
    }
}
