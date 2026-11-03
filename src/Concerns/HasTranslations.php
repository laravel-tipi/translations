<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use Stringable;
use Throwable;
use Tipi\Support\Locale;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\AttributeNotTranslatableException;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;
use Tipi\Translations\Exceptions\MissingTranslationException;
use Tipi\Translations\ResolvedTranslation;
use Tipi\Translations\Support\MorphKeyType;
use Tipi\Translations\Support\StringKeyMorphMany;
use Tipi\Translations\Support\TranslatableAttributes;
use Tipi\Translations\Support\TranslatableColumnGuard;
use Tipi\Translations\TranslationLocales;

/**
 * Storage-independent translation behavior. Use HasJsonTranslations,
 * HasTableTranslations or HasSharedTableTranslations, which add the
 * storage.
 *
 * @mixin Model
 *
 * @phpstan-require-implements TranslatableModel
 */
trait HasTranslations
{
    /**
     * Changes not written yet: locale => attribute => value (null removes).
     * Protected, so it survives PHP serialization (queued closures, cache).
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $translationChanges = [];

    /**
     * The changes the current save writes. Set once "creating" or
     * "updating" listeners have run, kept until the save syncs the
     * model's original attributes, like dirty columns.
     *
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $translationWrite = null;

    /**
     * Whether $translationWrite has been written.
     */
    private bool $translationWriteStored = false;

    /**
     * Translated attributes changed by the last update: attribute => true.
     * Protected, so it survives PHP serialization like $changes.
     *
     * @var array<string, true>
     */
    protected array $changedTranslatedAttributes = [];

    /**
     * Changes written by the save() calls in progress, to make pending
     * again if their transaction fails.
     *
     * @var list<array<string, array<string, mixed>>>
     */
    private array $translationWriteLog = [];

    private int $translationSaveDepth = 0;

    /**
     * Whether the save in progress inserted the model's row, which its
     * transaction then holds locked.
     */
    private bool $translationOwnerInserted = false;

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
     * Prepare the model row for the changes, after "creating" or
     * "updating" listeners ran and before the row is written.
     *
     * @param  array<string, array<string, mixed>>  $changes
     */
    abstract protected function prepareTranslationWrite(array $changes): void;

    /**
     * Write the changes once the model row is written, before "created"
     * or "updated" listeners run.
     *
     * @param  array<string, array<string, mixed>>  $changes
     */
    abstract protected function writeTranslations(array $changes): void;

    /**
     * SQL that yields the stored value for one locale, NULL when missing,
     * with its bindings.
     *
     * @param  Builder<static>  $query
     * @return array{0: string, 1: list<mixed>}
     */
    abstract protected function translationValueSql(Builder $query, string $attribute, string $locale): array;

    /**
     * A condition comparing a value from translationValueSql().
     *
     * @param  Builder<static>  $query
     * @param  array{0: string, 1: list<mixed>}  $value
     * @return array{0: string, 1: list<mixed>}
     */
    abstract protected function compareTranslationSql(Builder $query, array $value, string $operator, string|int|float $operand): array;

    /**
     * ORDER BY expressions for a value from translationValueSql(), missing
     * values last.
     *
     * @param  Builder<static>  $query
     * @param  array{0: string, 1: list<mixed>}  $value
     * @return array{0: string, 1: list<mixed>}
     */
    abstract protected function orderTranslationSql(Builder $query, array $value, string $direction): array;

    /**
     * The value of the first locale that has one, per model: key => value,
     * typed like model reads.
     *
     * @param  Builder<static>  $query
     * @param  non-empty-list<string>  $locales
     * @return Collection<array-key, mixed>
     */
    abstract protected function pluckTranslationValues(Builder $query, string $attribute, array $locales, string $key): Collection;

    /**
     * @param  Builder<static>  $query
     */
    abstract protected function eagerLoadTranslations(Builder $query): void;

    /**
     * The relations whose rows hold this model's translations, deleted
     * with the model.
     *
     * @return list<string>
     */
    abstract protected function translationRowRelations(): array;

    /**
     * Read the stored translations again with locking reads, replacing
     * what the model loaded. Called with the model's row locked, inside a
     * transaction.
     */
    abstract protected function reloadTranslationsForUpdate(): void;

    /**
     * Rejects queries that use a translatable attribute as a column.
     */
    public static function bootHasTranslations(): void
    {
        static::addGlobalScope(new TranslatableColumnGuard);
    }

    /*
     | Declaration
     */

    /**
     * @return list<string>
     */
    public function getTranslatableAttributes(): array
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

    /**
     * Unsaved changes: locale => attribute => value (null removes it).
     * Like getDirty(), it still includes the changes a save is writing
     * while its "created", "updated" and "saved" listeners run.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getDirtyTranslations(): array
    {
        return $this->translationLocales()->sort(
            self::mergeTranslationChanges($this->translationWrite ?? [], $this->translationChanges),
        );
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
     |
     | Translations are written inside Eloquent's own save lifecycle, so
     | save(), saveQuietly(), saveOrIgnore(), push() and a model's own
     | save() all write them:
     |
     | 1. "saving" and "creating"/"updating" listeners run; changes they make
     |    are part of this save;
     | 2. the storage prepares the row (JSON storage encodes the columns);
     | 3. the row is written, then the translations (table and shared
     |    storage);
     | 4. "created"/"updated" and "saved" listeners run with everything
     |    stored, and see translated attributes dirty and changed like
     |    columns. Translations they change stay pending for the next save.
     */

    /**
     * Writes the model and its translations in one transaction. After a
     * failure every translation change is pending again, and a new model
     * is still new, so save() can be called again.
     */
    public function save(array $options = []): bool
    {
        $stateChanges = method_exists($this, 'pendingTranslationStateChanges') ? $this->pendingTranslationStateChanges() : [];

        if ($this->translationChanges === [] && $stateChanges === [] && ! $this->hasListenersBeforeWrite()) {
            return parent::save($options);
        }

        $before = $this->translationStorageSnapshot();
        $logged = count($this->translationWriteLog);
        $this->translationSaveDepth++;

        try {
            return $this->getConnection()->transaction(function () use ($options): bool {
                $saved = parent::save($options);

                // Drafts and sources (HasTranslationStates), in the same transaction.
                if ($saved && method_exists($this, 'writeTranslationStateChanges')) {
                    $this->writeTranslationStateChanges();
                }

                return $saved;
            });
        } catch (Throwable $exception) {
            $this->restoreAfterFailedSave($before, $logged);

            if (method_exists($this, 'restoreTranslationStateChanges')) {
                $this->restoreTranslationStateChanges($stateChanges);
            }

            throw $exception;
        } finally {
            if (--$this->translationSaveDepth === 0) {
                $this->translationWriteLog = [];
            }
        }
    }

    /**
     * Read, decide and write without lost updates. In one transaction:
     * locks the model's row, reads its translations (and translation states)
     * again with locking reads, calls $callback with the model, then saves
     * what the callback changed. No other save of the model can change its translations
     * between that read and the write, in any storage.
     *
     * The callback sees exactly what is stored, so it can compare it with
     * what a user started from and throw to abort. When the callback or
     * the save fails, nothing is written and the changes the callback made
     * are discarded: they were decided from a read whose lock is gone.
     *
     * Returns what the callback returns. Throws ModelNotFoundException when
     * the model's row no longer exists.
     *
     * @template TReturn
     *
     * @param  Closure(static): TReturn  $callback
     * @return TReturn
     */
    public function lockTranslationsForUpdate(Closure $callback): mixed
    {
        if (! $this->exists) {
            throw new LogicException('Translations of a model that is not saved yet cannot be locked.');
        }

        $states = in_array(HasTranslationStates::class, class_uses_recursive(static::class), true);

        if ($this->translationChanges !== [] || ($states && $this->pendingTranslationStateChanges() !== [])) {
            throw new LogicException('Save or discard the unsaved translation changes before locking the translations.');
        }

        try {
            return $this->getConnection()->transaction(function () use ($callback, $states): mixed {
                if (! $this->lockTranslationOwner()) {
                    throw (new ModelNotFoundException)->setModel(static::class, [$this->getKey()]);
                }

                $this->reloadTranslationsForUpdate();

                if ($states) {
                    // Drafts and sources are read, decided and written too.
                    $this->setRelation('translationStates', $this->translationStates()->lockForUpdate()->get());
                }

                $result = $callback($this);

                $this->save();

                return $result;
            });
        } catch (Throwable $exception) {
            $this->translationChanges = [];

            if ($states) {
                $this->restoreTranslationStateChanges([]);
            }

            throw $exception;
        }
    }

    /**
     * Unsaved translations make the model dirty, so Eloquent updates it
     * (timestamps, "updating"/"updated" events, touched owners) even when
     * only table-stored translations changed. getDirty() stays column-level:
     * it is what Eloquent writes.
     */
    public function isDirty($attributes = null): bool
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        return parent::isDirty($attributes) || $this->hasChanges($this->dirtyTranslatedAttributes(), $attributes);
    }

    /**
     * Whether the last update changed the attributes, translated ones
     * included. Like for columns, inserts do not count.
     */
    public function wasChanged($attributes = null): bool
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        return parent::wasChanged($attributes) || $this->hasChanges($this->changedTranslatedAttributes, $attributes);
    }

    public function syncChanges(): static
    {
        parent::syncChanges();

        $this->changedTranslatedAttributes = [];

        foreach ($this->translationWrite ?? [] as $values) {
            $this->changedTranslatedAttributes += array_fill_keys(array_keys($values), true);
        }

        return $this;
    }

    /**
     * Ends a save's dirty state, like it does for columns.
     */
    public function syncOriginal(): static
    {
        parent::syncOriginal();

        $this->translationWrite = null;

        return $this;
    }

    public function discardChanges(): static
    {
        parent::discardChanges();

        $this->translationChanges = [];
        $this->translationWrite = null;
        $this->changedTranslatedAttributes = [];

        return $this;
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
     * Deletes the model and the rows that hold its translations (table
     * and shared storage, translation states) in one transaction. A soft
     * delete keeps them.
     */
    public function delete()
    {
        if (! $this->exists || $this->ownedTranslationRelations() === []) {
            return parent::delete();
        }

        return $this->getConnection()->transaction(fn () => parent::delete());
    }

    /**
     * Hooks translations into Eloquent's lifecycle. Runs with events
     * disabled too (saveQuietly(), deleteQuietly()):
     *
     * - a write is prepared once "creating"/"updating" listeners ran, and
     *   written before "created"/"updated" listeners run;
     * - translation rows are deleted once "deleting" listeners allowed
     *   the delete, before the model's row.
     */
    protected function fireModelEvent($event, $halt = true)
    {
        if ($event === 'created' || $event === 'updated') {
            $this->translationOwnerInserted = $event === 'created';

            try {
                $this->storeTranslationWrite();
            } finally {
                $this->translationOwnerInserted = false;
            }
        }

        $result = parent::fireModelEvent($event, $halt);

        if (($event === 'creating' || $event === 'updating') && $result !== false) {
            $this->beginTranslationWrite();
        }

        if ($event === 'deleting' && $result !== false) {
            $this->deleteTranslationRows();
        }

        return $result;
    }

    /**
     * Eloquent skips the update, its "updated" event and syncChanges()
     * when no column changed. A translation-only change of a model
     * without timestamps (table or shared storage) is still an update.
     */
    protected function performUpdate(Builder $query)
    {
        if (! parent::performUpdate($query)) {
            return false;
        }

        if ($this->translationWrite !== null && ! $this->translationWriteStored) {
            $this->syncChanges();
            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * The replica carries the same translations, in every storage, as
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
     * Values are strings, finite numbers, backed enums or Stringable
     * objects. Numbers compare numerically: table storage by its column
     * types, JSON and shared storage when the stored JSON value is a
     * number.
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

        [$sql, $bindings] = $this->orderTranslationSql($query, $this->translationQueryValue($query, $attribute, $locale, $fallback), $direction);

        $query->orderByRaw($sql, $bindings);
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
        $this->ensureTranslatable($attribute);

        return $this->pluckTranslationValues(
            clone $query,
            $attribute,
            $this->translationQueryLocales($locale, $fallback),
            $this->qualifyColumn($key ?? $this->getKeyName()),
        );
    }

    /**
     * Eager load what reading translations needs: the translations
     * relation for table and shared storage, nothing for JSON storage.
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
    private function translationValue(string $attribute, string $locale): mixed
    {
        if (isset($this->translationChanges[$locale]) && array_key_exists($attribute, $this->translationChanges[$locale])) {
            return $this->translationChanges[$locale][$attribute];
        }

        return $this->storedTranslation($attribute, $locale);
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

    private function beginTranslationWrite(): void
    {
        if ($this->translationChanges === []) {
            $this->translationWrite = null;

            return;
        }

        $this->translationWrite = $this->translationChanges;
        $this->translationWriteStored = false;

        $this->prepareTranslationWrite($this->translationWrite);
    }

    private function storeTranslationWrite(): void
    {
        if ($this->translationWrite === null || $this->translationWriteStored) {
            return;
        }

        $changes = $this->translationWrite;
        $this->translationWriteStored = true;

        $this->writeTranslations($changes);

        // No listener ran since beginTranslationWrite(), so every change
        // written is still pending with the same value.
        foreach ($changes as $locale => $values) {
            $this->translationChanges[$locale] = array_diff_key($this->translationChanges[$locale] ?? [], $values);

            if ($this->translationChanges[$locale] === []) {
                unset($this->translationChanges[$locale]);
            }
        }

        if ($this->translationSaveDepth > 0) {
            $this->translationWriteLog[] = $changes;
        }

        if (method_exists($this, 'syncTranslationStates')) {
            $this->syncTranslationStates(array_map('strval', array_keys($changes)));
        }
    }

    /**
     * @return list<string>
     */
    private function ownedTranslationRelations(): array
    {
        $relations = $this->translationRowRelations();

        if (in_array(HasTranslationStates::class, class_uses_recursive(static::class), true)) {
            $relations[] = 'translationStates';
        }

        return $relations;
    }

    /**
     * Rows go before the model's row, so foreign keys without cascade do
     * not block the delete. The owner lock makes writers that are still
     * adding rows finish first, so their rows are deleted too.
     */
    private function deleteTranslationRows(): void
    {
        if (method_exists($this, 'isForceDeleting') && ! $this->isForceDeleting()) {
            return;
        }

        $relations = $this->ownedTranslationRelations();

        if ($relations === []) {
            return;
        }

        $this->lockTranslationOwner();

        foreach ($relations as $relation) {
            $this->{$relation}()->delete();
            $this->unsetRelation($relation);
        }
    }

    /**
     * Lock the model's row until the transaction ends. Writers lock it
     * before adding translation rows and delete() locks it before
     * deleting them, so a row is never added for a model being deleted.
     * Returns false when the row does not exist (any more).
     */
    private function lockTranslationOwner(): bool
    {
        return $this->newQueryWithoutScopes()
            ->whereKey($this->getKeyForSaveQuery())
            ->toBase()
            ->lockForUpdate()
            ->first([$this->getKeyName()]) !== null;
    }

    /**
     * Before translation rows are added: lock the model's row, or fail
     * when it was deleted meanwhile, as a foreign key would.
     */
    protected function lockTranslationOwnerOrFail(): void
    {
        // An insert locks the new row; an update may have matched no row.
        if (! $this->translationOwnerInserted && ! $this->lockTranslationOwner()) {
            throw (new ModelNotFoundException)->setModel(static::class, [$this->getKey()]);
        }
    }

    /**
     * Rows on another connection would not be part of the model's
     * transaction or lock.
     */
    protected function ensureTranslationConnection(Model $related): void
    {
        $connection = $this->getConnection()->getName();
        $relatedConnection = $related->getConnection()->getName();

        if ($connection !== $relatedConnection) {
            throw InvalidTranslatableModelException::connectionMismatch(static::class, $related::class, (string) $connection, (string) $relatedConnection);
        }
    }

    /**
     * A morphMany() to one of the package's polymorphic tables, for the
     * configured key type.
     *
     * @template TRelated of Model
     *
     * @param  class-string<TRelated>  $related
     * @return MorphMany<TRelated, $this>
     */
    protected function translationMorphMany(string $related): MorphMany
    {
        $keyType = MorphKeyType::configured();
        $keyType->ensureSupports($this);

        // Before any query: some databases reject a key that does not fit
        // the column, others would find nothing or store it silently.
        if ($this->getKey() !== null) {
            $keyType->ensureKey($this);
        }

        if ($keyType !== MorphKeyType::String) {
            return $this->morphMany($related, 'translatable');
        }

        $instance = $this->newRelatedInstance($related);
        $table = $instance->getTable();

        return new StringKeyMorphMany($instance->newQuery(), $this, "{$table}.translatable_type", "{$table}.translatable_id", $this->getKeyName());
    }

    private function hasListenersBeforeWrite(): bool
    {
        $dispatcher = static::getEventDispatcher();

        if ($dispatcher === null) {
            return false;
        }

        foreach (['saving', 'creating', 'updating'] as $event) {
            if ($dispatcher->hasListeners("eloquent.{$event}: ".static::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a failed save restores: whether the model existed, its key, the
     * translatable columns (JSON storage) and the loaded translation rows.
     *
     * @return array{exists: bool, key: array<string, mixed>, attributes: array<string, mixed>, original: array<string, mixed>, translations: mixed}
     */
    private function translationStorageSnapshot(): array
    {
        $translatable = TranslatableAttributes::of(static::class);

        return [
            'exists' => $this->exists,
            'key' => array_intersect_key($this->attributes, [$this->getKeyName() => true]),
            'attributes' => array_intersect_key($this->attributes, $translatable),
            'original' => array_intersect_key($this->original, $translatable),
            'translations' => $this->relations['translations'] ?? null,
        ];
    }

    /**
     * @param  array{exists: bool, key: array<string, mixed>, attributes: array<string, mixed>, original: array<string, mixed>, translations: mixed}  $before
     */
    private function restoreAfterFailedSave(array $before, int $logged): void
    {
        $translatable = TranslatableAttributes::of(static::class);

        $this->attributes = array_replace(array_diff_key($this->attributes, $translatable), $before['attributes']);
        $this->original = array_replace(array_diff_key($this->original, $translatable), $before['original']);

        // The insert was rolled back: the model is new again.
        if (! $before['exists'] && $this->exists) {
            $this->exists = false;
            $this->wasRecentlyCreated = false;
            $this->attributes = array_replace(array_diff_key($this->attributes, [$this->getKeyName() => true]), $before['key']);
        }

        // Write rows replace the loaded collection, so the old one is intact.
        if ($before['translations'] !== null) {
            $this->setRelation('translations', $before['translations']);
        } else {
            $this->unsetRelation('translations');
        }

        // State writes change the loaded states in place: load them again.
        $this->unsetRelation('translationStates');

        // Changes made since take precedence over the ones rolled back.
        foreach (array_splice($this->translationWriteLog, $logged) as $changes) {
            $this->translationChanges = self::mergeTranslationChanges($changes, $this->translationChanges);
        }

        $this->translationWrite = null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $base
     * @param  array<string, array<string, mixed>>  $changes
     * @return array<string, array<string, mixed>>
     */
    private static function mergeTranslationChanges(array $base, array $changes): array
    {
        foreach ($changes as $locale => $values) {
            $base[$locale] = array_replace($base[$locale] ?? [], $values);
        }

        return $base;
    }

    /**
     * @return array<string, true>
     */
    private function dirtyTranslatedAttributes(): array
    {
        $attributes = [];

        foreach ([$this->translationChanges, $this->translationWrite ?? []] as $changes) {
            foreach ($changes as $values) {
                $attributes += array_fill_keys(array_keys($values), true);
            }
        }

        return $attributes;
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

        if (str_contains($operator, 'ilike') && $query->getConnection()->getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException("Operator [{$operator}] is only supported on PostgreSQL.");
        }

        $translation = $this->translationQueryValue($query, $attribute, $locale, $fallback);

        if ($value === null) {
            $null = match ($operator) {
                '=' => 'is null',
                '!=', '<>' => 'is not null',
                default => throw new InvalidArgumentException("Operator [{$operator}] cannot be compared with null."),
            };

            $query->whereRaw("{$translation[0]} {$null}", $translation[1], $boolean);

            return;
        }

        [$sql, $bindings] = $this->compareTranslationSql($query, $translation, $operator, self::translationOperand($operator, $value));

        $query->whereRaw($sql, $bindings, $boolean);
    }

    /**
     * Translations compare with strings and numbers. Backed enums compare
     * by value, Stringable objects as strings.
     */
    private static function translationOperand(string $operator, mixed $value): string|int|float
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (! is_string($value) && ! is_int($value) && ! (is_float($value) && is_finite($value))) {
            throw new InvalidArgumentException('Translations can be compared with strings and finite numbers, ['.get_debug_type($value).'] given.');
        }

        if (str_contains($operator, 'like') && ! is_string($value)) {
            throw new InvalidArgumentException("Operator [{$operator}] needs a string, [".get_debug_type($value).'] given.');
        }

        return $value;
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

        $parts = [];
        $bindings = [];

        foreach ($this->translationQueryLocales($locale, $fallback) as $candidate) {
            [$sql, $candidateBindings] = $this->translationValueSql($query, $attribute, $candidate);

            $parts[] = $sql;
            array_push($bindings, ...$candidateBindings);
        }

        return [count($parts) === 1 ? $parts[0] : 'coalesce('.implode(', ', $parts).')', $bindings];
    }

    /**
     * @return non-empty-list<string>
     */
    private function translationQueryLocales(string|Locale|null $locale, bool $fallback): array
    {
        $locales = $this->translationLocales();
        $code = $locales->code($locale);

        return $fallback ? [$code, ...$locales->fallbacks($code)] : [$code];
    }
}
