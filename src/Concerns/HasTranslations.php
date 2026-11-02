<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;
use Tipi\Support\Locale;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\AttributeNotTranslatableException;
use Tipi\Translations\Exceptions\MissingTranslationException;
use Tipi\Translations\ResolvedTranslation;
use Tipi\Translations\Support\TranslatableAttributes;
use Tipi\Translations\TranslationLocales;

/**
 * Storage-independent translation behavior. Use HasJsonTranslations or
 * HasTableTranslations, which add the storage.
 *
 * @mixin Model
 *
 * @phpstan-require-implements TranslatableModel
 */
trait HasTranslations
{
    /**
     * Changes not stored yet: locale => attribute => value (null removes).
     * Protected, so it survives PHP serialization (queued closures, cache).
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $translationChanges = [];

    /*
     | Storage primitives, implemented by the storage traits.
     */

    /**
     * The stored value, ignoring unsaved changes. Null when missing.
     */
    abstract protected function storedTranslation(string $attribute, string $locale): mixed;

    /**
     * Every stored non-null value, ignoring unsaved changes.
     *
     * @return array<string, array<string, mixed>> locale => attribute => value
     */
    abstract protected function storedTranslations(): array;

    /**
     * Whether the attribute's data is available for serialization (for
     * example, not left out by a select()).
     */
    abstract protected function translationIsLoaded(string $attribute): bool;

    /**
     * Runs inside the save transaction, before the model row is written.
     *
     * @param  array<string, array<string, mixed>>  $changes
     */
    abstract protected function writeTranslationsBeforeSave(array $changes): void;

    /**
     * Runs inside the save transaction, after the model row is written.
     * Returns whether anything was written.
     *
     * @param  array<string, array<string, mixed>>  $changes
     */
    abstract protected function writeTranslationsAfterSave(array $changes): bool;

    /**
     * SQL that yields the stored value for one locale, with its bindings.
     *
     * @param  Builder<static>  $query
     * @return array{0: string, 1: list<mixed>}
     */
    abstract protected function translationValueSql(Builder $query, string $attribute, string $locale): array;

    /**
     * @param  Builder<static>  $query
     */
    abstract protected function eagerLoadTranslations(Builder $query): void;

    /*
     | Declaration
     */

    /**
     * @return list<string>
     */
    public function translatableAttributes(): array
    {
        return array_keys(TranslatableAttributes::of(static::class));
    }

    public function isTranslatableAttribute(string $attribute): bool
    {
        return isset(TranslatableAttributes::of(static::class)[$attribute]);
    }

    /*
     | Implicit attribute access
     */

    /**
     * Translatable attributes resolve the current locale with fallback.
     */
    public function getAttribute($key): mixed
    {
        if (is_string($key) && isset(TranslatableAttributes::of(static::class)[$key])) {
            return $this->findTranslation($key, $this->translationLocales()->current(), true)[1];
        }

        return parent::getAttribute($key);
    }

    /**
     * Assigning a translatable attribute writes the current locale.
     */
    public function setAttribute($key, $value): mixed
    {
        if (is_string($key) && isset(TranslatableAttributes::of(static::class)[$key])) {
            return $this->changeTranslation($key, $this->translationLocales()->current(), $value);
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Translatable attributes serialize like $model->name: the current
     * locale, with fallback. $hidden and $visible apply as usual.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        $translatable = $this->getArrayableItems(TranslatableAttributes::of(static::class));

        if ($translatable === []) {
            return $attributes;
        }

        $locale = $this->translationLocales()->current();

        foreach (array_keys($translatable) as $attribute) {
            if ($this->translationIsLoaded($attribute)) {
                $attributes[$attribute] = $this->findTranslation($attribute, $locale, true)[1];
            }
        }

        return $attributes;
    }

    /*
     | Explicit reading
     */

    public function getTranslation(string $attribute, string|Locale|null $locale = null, bool $fallback = false): mixed
    {
        $this->ensureTranslatable($attribute);

        return $this->findTranslation($attribute, $this->translationLocales()->code($locale), $fallback)[1];
    }

    public function getTranslationOrFail(string $attribute, string|Locale|null $locale = null, bool $fallback = false): mixed
    {
        $this->ensureTranslatable($attribute);

        $code = $this->translationLocales()->code($locale);

        [$source, $value] = $this->findTranslation($attribute, $code, $fallback);

        if ($source === null) {
            throw new MissingTranslationException(static::class, $this->getKey(), $attribute, $code, $fallback);
        }

        return $value;
    }

    public function resolveTranslation(string $attribute, string|Locale|null $locale = null): ResolvedTranslation
    {
        $this->ensureTranslatable($attribute);

        $code = $this->translationLocales()->code($locale);

        [$source, $value] = $this->findTranslation($attribute, $code, true);

        return new ResolvedTranslation($attribute, $code, $source, $value);
    }

    public function hasTranslation(string $attribute, string|Locale|null $locale = null): bool
    {
        return $this->getTranslation($attribute, $locale) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTranslations(string|Locale|null $locale = null): array
    {
        $code = $this->translationLocales()->code($locale);

        $values = [];

        foreach (array_keys(TranslatableAttributes::of(static::class)) as $attribute) {
            $value = $this->translationValue($attribute, $code);

            if ($value !== null) {
                $values[$attribute] = $value;
            }
        }

        return $values;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAllTranslations(): array
    {
        $translations = $this->storedTranslations();

        foreach ($this->translationChanges as $locale => $changes) {
            foreach ($changes as $attribute => $value) {
                if ($value === null) {
                    unset($translations[$locale][$attribute]);
                } else {
                    $translations[$locale][$attribute] = $value;
                }
            }

            if (($translations[$locale] ?? []) === []) {
                unset($translations[$locale]);
            }
        }

        $order = TranslatableAttributes::of(static::class);

        foreach ($translations as $locale => $values) {
            $translations[$locale] = array_intersect_key(array_replace($order, $values), $values);
        }

        return $this->translationLocales()->sort($translations);
    }

    /**
     * @return list<string>
     */
    public function getTranslatedLocales(): array
    {
        return array_keys($this->getAllTranslations());
    }

    public function hasUnsavedTranslations(): bool
    {
        return $this->translationChanges !== [];
    }

    /*
     | Writing
     */

    public function setTranslation(string $attribute, string|Locale $locale, mixed $value): static
    {
        $this->ensureTranslatable($attribute);

        return $this->changeTranslation($attribute, $this->translationLocales()->code($locale), $value);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setTranslations(string|Locale $locale, array $values): static
    {
        $code = $this->translationLocales()->code($locale);

        foreach (array_keys($values) as $attribute) {
            $this->ensureTranslatable((string) $attribute);
        }

        foreach ($values as $attribute => $value) {
            $this->changeTranslation((string) $attribute, $code, $value);
        }

        return $this;
    }

    /**
     * @param  array<string, array<string, mixed>>  $translations
     */
    public function fillTranslations(array $translations): static
    {
        $locales = $this->translationLocales();

        foreach ($translations as $locale => $values) {
            $locales->code((string) $locale);

            if (! is_array($values)) {
                throw new InvalidArgumentException("Translations for locale [{$locale}] must be an array of attribute values.");
            }

            foreach (array_keys($values) as $attribute) {
                $this->ensureTranslatable((string) $attribute);
            }
        }

        foreach ($translations as $locale => $values) {
            foreach ($values as $attribute => $value) {
                $this->changeTranslation((string) $attribute, (string) $locale, $value);
            }
        }

        return $this;
    }

    public function forgetTranslation(string $attribute, string|Locale $locale): static
    {
        return $this->setTranslation($attribute, $locale, null);
    }

    public function forgetTranslations(string|Locale $locale): static
    {
        $code = $this->translationLocales()->code($locale);

        foreach (array_keys(TranslatableAttributes::of(static::class)) as $attribute) {
            $this->changeTranslation($attribute, $code, null);
        }

        return $this;
    }

    /*
     | Persistence
     */

    /**
     * Saving a model with translation changes writes the model and its
     * translations in one transaction. Without translation changes this
     * is Eloquent's save().
     */
    public function save(array $options = []): bool
    {
        if ($this->translationChanges === []) {
            return parent::save($options);
        }

        $connection = $this->getConnection();

        $connection->beginTransaction();

        try {
            $saved = $this->saveWithTranslations($options);
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        if ($saved) {
            $connection->commit();
        } else {
            $connection->rollBack();
        }

        return $saved;
    }

    /**
     * Reloading the model discards unsaved translation changes, like it
     * discards unsaved attribute changes.
     */
    public function refresh(): static
    {
        if ($this->exists) {
            parent::refresh();

            $this->translationChanges = [];
        }

        return $this;
    }

    /**
     * The replica carries the same translations, for both storages, as
     * unsaved changes. Attributes in $except are left out, as for columns.
     */
    public function replicate(?array $except = null): static
    {
        $replica = parent::replicate($except);

        // Eloquent copies loaded relations; these rows belong to this model.
        $replica->unsetRelation('translations');
        $replica->unsetRelation('translationStates');

        $excluded = array_flip($except ?? []);

        foreach ($this->getAllTranslations() as $locale => $values) {
            foreach (array_diff_key($values, $excluded) as $attribute => $value) {
                $replica->changeTranslation($attribute, $locale, $value);
            }
        }

        return $replica;
    }

    /*
     | Query scopes
     */

    /**
     * Compare a translated value. Exact locale (the current locale by
     * default) unless $fallback is true. A null value with "=" or "!="
     * tests for a missing or present translation.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function whereTranslation(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        string|Locale|null $locale = null,
        bool $fallback = false,
    ): void {
        $this->addTranslationWhere($query, $attribute, $operator, $value, $locale, $fallback, 'and');
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function orWhereTranslation(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        string|Locale|null $locale = null,
        bool $fallback = false,
    ): void {
        $this->addTranslationWhere($query, $attribute, $operator, $value, $locale, $fallback, 'or');
    }

    /**
     * Models that have a value for the attribute in the exact locale.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function whereHasTranslation(Builder $query, string $attribute, string|Locale|null $locale = null): void
    {
        $this->addTranslationWhere($query, $attribute, '!=', null, $locale, false, 'and');
    }

    /**
     * Models that have no value for the attribute in the exact locale.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function whereDoesntHaveTranslation(Builder $query, string $attribute, string|Locale|null $locale = null): void
    {
        $this->addTranslationWhere($query, $attribute, '=', null, $locale, false, 'and');
    }

    /**
     * Order by a translated value. Missing values sort last in both
     * directions, on every database.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function orderByTranslation(
        Builder $query,
        string $attribute,
        string $direction = 'asc',
        string|Locale|null $locale = null,
        bool $fallback = false,
    ): void {
        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Order direction must be \"asc\" or \"desc\", [{$direction}] given.");
        }

        [$sql, $bindings] = $this->translationQueryValue($query, $attribute, $locale, $fallback);

        $query->orderByRaw("case when {$sql} is null then 1 else 0 end, {$sql} {$direction}", [...$bindings, ...$bindings]);
    }

    /**
     * Translated values keyed by $key (the primary key by default), read
     * in one query without hydrating models.
     *
     * @param  Builder<static>  $query
     * @return Collection<array-key, mixed>
     */
    #[Scope]
    protected function pluckTranslation(
        Builder $query,
        string $attribute,
        ?string $key = null,
        string|Locale|null $locale = null,
        bool $fallback = false,
    ): Collection {
        [$sql, $bindings] = $this->translationQueryValue($query, $attribute, $locale, $fallback);

        $base = (clone $query)->toBase();
        $grammar = $base->getGrammar();

        return $base
            ->select($this->qualifyColumn($key ?? $this->getKeyName()).' as tipi_translation_key')
            ->selectRaw("{$sql} as ".$grammar->wrap('tipi_translation_value'), $bindings)
            ->pluck('tipi_translation_value', 'tipi_translation_key');
    }

    /**
     * Eager load what reading translations needs: the translations
     * relation for table storage, nothing for JSON storage.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withTranslations(Builder $query): void
    {
        $this->eagerLoadTranslations($query);
    }

    /*
     | Internals
     */

    /**
     * The value for the locale, including unsaved changes. Null when
     * missing.
     */
    protected function translationValue(string $attribute, string $locale): mixed
    {
        if (isset($this->translationChanges[$locale]) && array_key_exists($attribute, $this->translationChanges[$locale])) {
            return $this->translationChanges[$locale][$attribute];
        }

        return $this->storedTranslation($attribute, $locale);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function translationChanges(): array
    {
        return $this->translationChanges;
    }

    protected function translationLocales(): TranslationLocales
    {
        return app(TranslationLocales::class);
    }

    /**
     * @return array{0: string|null, 1: mixed} [source locale, value]
     */
    private function findTranslation(string $attribute, string $locale, bool $fallback): array
    {
        $value = $this->translationValue($attribute, $locale);

        if ($value !== null) {
            return [$locale, $value];
        }

        if ($fallback) {
            foreach ($this->translationLocales()->fallbacks($locale) as $candidate) {
                $value = $this->translationValue($attribute, $candidate);

                if ($value !== null) {
                    return [$candidate, $value];
                }
            }
        }

        return [null, null];
    }

    private function changeTranslation(string $attribute, string $locale, mixed $value): static
    {
        if ($value === $this->storedTranslation($attribute, $locale)) {
            unset($this->translationChanges[$locale][$attribute]);

            if (($this->translationChanges[$locale] ?? null) === []) {
                unset($this->translationChanges[$locale]);
            }

            return $this;
        }

        $this->translationChanges[$locale][$attribute] = $value;

        return $this;
    }

    private function saveWithTranslations(array $options): bool
    {
        $changes = $this->translationChanges;

        $this->writeTranslationsBeforeSave($changes);

        $modelWillBeWritten = ! $this->exists || $this->isDirty();

        if (! parent::save($options)) {
            return false;
        }

        $translationsWritten = $this->writeTranslationsAfterSave($changes);

        $this->translationChanges = [];

        // Keep updated_at meaningful when only table-stored translations
        // changed, as it is for JSON-stored ones.
        if ($translationsWritten && ! $modelWillBeWritten && $this->usesTimestamps()) {
            $this->touchQuietly();
        }

        if (method_exists($this, 'syncTranslationStates')) {
            $this->syncTranslationStates(array_keys($changes));
        }

        return true;
    }

    private function ensureTranslatable(string $attribute): void
    {
        if (! isset(TranslatableAttributes::of(static::class)[$attribute])) {
            throw new AttributeNotTranslatableException(static::class, $attribute);
        }
    }

    /**
     * @param  Builder<static>  $query
     */
    private function addTranslationWhere(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        string|Locale|null $locale,
        bool $fallback,
        string $boolean,
    ): void {
        $operator = strtolower(trim($operator));

        if (! in_array($operator, ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like', 'ilike', 'not ilike'], true)) {
            throw new InvalidArgumentException("Unsupported translation query operator [{$operator}].");
        }

        [$sql, $bindings] = $this->translationQueryValue($query, $attribute, $locale, $fallback);

        if ($value === null) {
            $null = match ($operator) {
                '=' => 'is null',
                '!=', '<>' => 'is not null',
                default => throw new InvalidArgumentException("Operator [{$operator}] cannot be compared with null."),
            };

            $query->whereRaw("{$sql} {$null}", $bindings, $boolean);

            return;
        }

        $query->whereRaw("{$sql} {$operator} ?", [...$bindings, $value], $boolean);
    }

    /**
     * SQL for the value of the attribute in the locale, coalesced over the
     * fallback chain when requested.
     *
     * @param  Builder<static>  $query
     * @return array{0: string, 1: list<mixed>}
     */
    private function translationQueryValue(Builder $query, string $attribute, string|Locale|null $locale, bool $fallback): array
    {
        $this->ensureTranslatable($attribute);

        $locales = $this->translationLocales();
        $code = $locales->code($locale);

        $parts = [];
        $bindings = [];

        foreach ($fallback ? [$code, ...$locales->fallbacks($code)] : [$code] as $candidate) {
            [$sql, $candidateBindings] = $this->translationValueSql($query, $attribute, $candidate);

            $parts[] = $sql;
            array_push($bindings, ...$candidateBindings);
        }

        return [count($parts) === 1 ? $parts[0] : 'coalesce('.implode(', ', $parts).')', $bindings];
    }
}
