<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection as BaseCollection;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;

/**
 * Stores translations in a dedicated table with one row per locale: the
 * model's foreign key, a locale_code column and one column per
 * translatable attribute.
 *
 * - The rows are the translations() relation. Eager load it
 *   (with('translations') or withTranslations()) when reading many models.
 * - Values are read through the translation model, so its casts apply.
 * - A row without any value is deleted.
 * - Rows are deleted with the model (not on soft delete), in one
 *   transaction. A foreign key with cascade also covers deletes that
 *   bypass the model.
 * - The rows are hidden from toArray(); translated attributes are
 *   serialized instead.
 *
 * @mixin Model
 */
trait HasTableTranslations
{
    use HasTranslations;

    /**
     * The loaded translations collection that $translationRowsByLocale
     * indexes.
     */
    private ?Collection $indexedTranslationRows = null;

    /**
     * @var array<string, Model>
     */
    private array $translationRowsByLocale = [];

    /**
     * The rows are storage: serialize the translated attributes instead,
     * as JSON storage does. makeVisible('translations') to include them.
     */
    public function initializeHasTableTranslations(): void
    {
        $this->makeHidden('translations');
    }

    /**
     * The translation rows. By convention the translation model is the
     * model's class name followed by "Translation" (Wine → WineTranslation).
     * Define this relation on the model to use another one.
     *
     * Do not constrain this relation when eager loading it: translations
     * are read from it, so a partial list reads as missing translations.
     *
     * @return HasMany<Model, $this>
     */
    public function translations(): HasMany
    {
        $translationModel = static::class.'Translation';

        if (! class_exists($translationModel)) {
            throw InvalidTranslatableModelException::missingTranslationModel(static::class, $translationModel);
        }

        return $this->hasMany($translationModel);
    }

    protected function storedTranslation(string $attribute, string $locale): mixed
    {
        return ($this->translationRows()[$locale] ?? null)?->getAttribute($attribute);
    }

    protected function storedTranslations(): array
    {
        $translations = [];

        foreach ($this->translationRows() as $locale => $row) {
            foreach ($this->getTranslatableAttributes() as $attribute) {
                $value = $row->getAttribute($attribute);

                if ($value !== null) {
                    $translations[$locale][$attribute] = $value;
                }
            }
        }

        return $translations;
    }

    protected function translationIsLoaded(string $attribute): bool
    {
        return true;
    }

    protected function prepareTranslationWrite(array $changes): void
    {
        // Rows need the model's key, so they are written after the model.
    }

    /**
     * Each locale is written with one atomic upsert of the changed
     * columns, so concurrent writers never lose each other's locales or
     * attributes, and a row inserted meanwhile is updated instead. A row
     * left without values is deleted only if it is still empty.
     */
    protected function writeTranslations(array $changes): void
    {
        $relation = $this->translations();
        $this->ensureTranslationConnection($relation->getRelated());
        $this->lockTranslationOwnerOrFail();
        $owner = $relation->make()->getAttributes();
        $uniqueBy = [...array_keys($owner), 'locale_code'];

        /** @var array<string, list<array<string, mixed>>> $upserts rows grouped by their columns */
        $upserts = [];
        /** @var array<string, list<string>> $cleared locales grouped by the attributes they clear */
        $cleared = [];

        foreach ($this->translationLocales()->sort($changes) as $locale => $values) {
            $locale = (string) $locale;
            $group = implode(',', array_keys($values));

            if (array_filter($values, fn (mixed $value): bool => $value !== null) === []) {
                $cleared[$group][] = $locale;

                continue;
            }

            // Fill a row model, so the translation model's casts and
            // mutators produce the stored values.
            $row = $relation->make()->forceFill([...$values, 'locale_code' => $locale]);

            $upserts[$group][] = $row->getAttributes();
        }

        foreach ($upserts as $rows) {
            $update = array_values(array_diff(array_keys($rows[0]), $uniqueBy));

            $relation->getRelated()->newQuery()->upsert($rows, $uniqueBy, $update);
        }

        if ($cleared !== []) {
            foreach ($cleared as $group => $locales) {
                $this->translations()
                    ->whereIn('locale_code', $locales)
                    ->update(array_fill_keys(explode(',', $group), null));
            }

            $this->translations()
                ->whereIn('locale_code', array_merge(...array_values($cleared)))
                ->where(function (Builder $query): void {
                    foreach ($this->getTranslatableAttributes() as $attribute) {
                        $query->whereNull($attribute);
                    }
                })
                ->delete();
        }

        if ($this->relationLoaded('translations')) {
            $this->setRelation('translations', $this->translations()->get());
        }
    }

    protected function reloadTranslationsForUpdate(): void
    {
        $this->setRelation('translations', $this->translations()->lockForUpdate()->get());
    }

    protected function translationValueSql(Builder $query, string $attribute, string $locale): array
    {
        $relation = $this->translations();
        $related = $relation->getRelated();

        $value = $related->newQuery()
            ->select($related->qualifyColumn($attribute))
            ->whereColumn($relation->getQualifiedForeignKeyName(), $relation->getQualifiedParentKeyName())
            ->where($related->qualifyColumn('locale_code'), $locale)
            ->limit(1)
            ->toBase();

        return ['('.$value->toSql().')', $value->getBindings()];
    }

    /**
     * Values compare like the translation table's columns.
     */
    protected function compareTranslationSql(Builder $query, array $value, string $operator, string|int|float $operand): array
    {
        return ["{$value[0]} {$operator} ?", [...$value[1], $operand]];
    }

    protected function orderTranslationSql(Builder $query, array $value, string $direction): array
    {
        [$sql, $bindings] = $value;

        return ["case when {$sql} is null then 1 else 0 end, {$sql} {$direction}", [...$bindings, ...$bindings]];
    }

    /**
     * Values are cast by the translation model, like model reads.
     */
    protected function pluckTranslationValues(Builder $query, string $attribute, array $locales, string $key): BaseCollection
    {
        $parts = [];
        $bindings = [];

        foreach ($locales as $locale) {
            [$sql, $partBindings] = $this->translationValueSql($query, $attribute, $locale);
            $parts[] = $sql;
            array_push($bindings, ...$partBindings);
        }

        $grammar = $query->getQuery()->getGrammar();
        $value = count($parts) === 1 ? $parts[0] : 'coalesce('.implode(', ', $parts).')';
        $row = $this->translations()->getRelated()->newInstance();

        return $query->toBase()
            ->select($key.' as tipi_translation_key')
            ->selectRaw("{$value} as ".$grammar->wrap('tipi_translation_value'), $bindings)
            ->pluck('tipi_translation_value', 'tipi_translation_key')
            ->map(fn (mixed $raw): mixed => $raw === null ? null : $row->setRawAttributes([$attribute => $raw])->getAttribute($attribute));
    }

    protected function eagerLoadTranslations(Builder $query): void
    {
        $query->with('translations');
    }

    protected function translationRowRelations(): array
    {
        return ['translations'];
    }

    /**
     * Rows of the loaded (or lazily loaded) relation, keyed by locale.
     *
     * @return array<string, Model>
     */
    private function translationRows(): array
    {
        $rows = $this->getRelationValue('translations');

        if ($rows !== $this->indexedTranslationRows) {
            $this->translationRowsByLocale = [];

            foreach ($rows as $row) {
                $this->translationRowsByLocale[(string) $row->getAttribute('locale_code')] = $row;
            }

            $this->indexedTranslationRows = $rows;
        }

        return $this->translationRowsByLocale;
    }
}
