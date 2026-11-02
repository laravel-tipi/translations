<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

use Tipi\Localization\Exceptions\LocaleNotFoundException;
use Tipi\Support\Locale;
use Tipi\Translations\Exceptions\AttributeNotTranslatableException;
use Tipi\Translations\Exceptions\MissingTranslationException;
use Tipi\Translations\ResolvedTranslation;

/**
 * An Eloquent model with translatable attributes. Implemented by
 * HasJsonTranslations and HasTableTranslations.
 *
 * Reading:
 * - $model->name resolves the current locale with fallback;
 * - explicit methods return the exact stored value unless fallback is
 *   requested.
 *
 * A translation is missing when its value is null. Empty strings, 0 and
 * false are values and never trigger fallback.
 *
 * Writes are kept on the model until save(), which stores them atomically.
 * Unsaved translations make the model dirty (isDirty('name')).
 *
 * Reads that name a locale, and every write, throw
 * LocaleNotFoundException for a locale that does not exist, and
 * AttributeNotTranslatableException for an unknown attribute.
 */
interface TranslatableModel
{
    /**
     * @return list<string>
     */
    public function getTranslatableAttributes(): array;

    public function isTranslatableAttribute(string $attribute): bool;

    /**
     * The value stored for the locale (the current locale by default), or
     * null. With $fallback, the fallback locales are tried as well.
     *
     * @throws AttributeNotTranslatableException
     * @throws LocaleNotFoundException
     */
    public function getTranslation(string $attribute, string|Locale|null $locale = null, bool $fallback = false): mixed;

    /**
     * Like getTranslation(), but a missing value throws.
     *
     * @throws MissingTranslationException
     */
    public function getTranslationOrFail(string $attribute, string|Locale|null $locale = null, bool $fallback = false): mixed;

    /**
     * Resolve with fallback and report where the value came from.
     */
    public function resolveTranslation(string $attribute, string|Locale|null $locale = null): ResolvedTranslation;

    /**
     * Whether the locale (the current locale by default) has a value.
     */
    public function hasTranslation(string $attribute, string|Locale|null $locale = null): bool;

    /**
     * The values stored for one locale (the current locale by default):
     * attribute => value, without missing attributes.
     *
     * @return array<string, mixed>
     */
    public function getTranslations(string|Locale|null $locale = null): array;

    /**
     * Every stored value: locale => attribute => value, locales in display
     * order. Includes unsaved changes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllTranslations(): array;

    /**
     * Locales that have at least one value, in display order.
     *
     * @return list<string>
     */
    public function getTranslatedLocales(): array;

    /**
     * Set one value. Null removes it. Stored on save().
     */
    public function setTranslation(string $attribute, string|Locale $locale, mixed $value): static;

    /**
     * Set several values of one locale: attribute => value.
     *
     * @param  array<string, mixed>  $values
     */
    public function setTranslations(string|Locale $locale, array $values): static;

    /**
     * Set values of several locales: locale => attribute => value. Locales
     * and attributes that are not given are left untouched.
     *
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function fillTranslations(array $translations): static;

    public function forgetTranslation(string $attribute, string|Locale $locale): static;

    /**
     * Remove every value of the locale.
     */
    public function forgetTranslations(string|Locale $locale): static;

    /**
     * Changes save() has not stored yet: locale => attribute => value
     * (null removes it). isDirty(), isClean() and wasChanged() also
     * understand translated attributes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getDirtyTranslations(): array;
}
