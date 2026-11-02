<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
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

    public static function bootHasTableTranslations(): void
    {
        static::deleting(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            /** @var Model&self $model */
            $model->translations()->delete();
            $model->unsetRelation('translations');
        });
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
            foreach ($this->translatableAttributes() as $attribute) {
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

    protected function writeTranslationsBeforeSave(array $changes): void
    {
        // Rows need the model's key, so they are written after it is saved.
    }

    protected function writeTranslationsAfterSave(array $changes): bool
    {
        if ($this->relationLoaded('translations')) {
            $loaded = $this->getRelation('translations');
            $rows = $this->translationRows();
        } else {
            $loaded = null;
            $rows = $this->translations()
                ->whereIn('locale_code', array_map('strval', array_keys($changes)))
                ->get()
                ->keyBy('locale_code')
                ->all();
        }

        $written = false;

        foreach ($changes as $locale => $values) {
            $locale = (string) $locale;
            $row = $rows[$locale] ?? null;

            if ($row === null) {
                $values = array_filter($values, fn (mixed $value): bool => $value !== null);

                if ($values === []) {
                    continue;
                }

                $inserted = $this->insertTranslationRow($locale, $values);
                $loaded?->push($inserted);
                $written = true;

                continue;
            }

            $row->forceFill($values);

            if ($this->translationRowIsEmpty($row)) {
                $row->delete();

                if ($loaded !== null) {
                    $loaded = $loaded->reject(fn (Model $candidate): bool => $candidate === $row)->values();
                    $this->setRelation('translations', $loaded);
                }

                $written = true;

                continue;
            }

            if ($row->isDirty()) {
                $row->save();
                $written = true;
            }
        }

        $this->indexedTranslationRows = null;

        return $written;
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

    protected function eagerLoadTranslations(Builder $query): void
    {
        $query->with('translations');
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

    /**
     * @param  array<string, mixed>  $values
     */
    private function insertTranslationRow(string $locale, array $values): Model
    {
        $row = $this->translations()->make();
        $row->forceFill([...$values, 'locale_code' => $locale]);

        try {
            // A savepoint, so a failed insert leaves the save transaction usable.
            $row->getConnection()->transaction(fn (): bool => $row->save());

            return $row;
        } catch (UniqueConstraintViolationException) {
            // Another process stored this locale since the rows were read.
            $existing = $this->translations()->where('locale_code', $locale)->firstOrFail();
            $existing->forceFill($values)->save();

            return $existing;
        }
    }

    private function translationRowIsEmpty(Model $row): bool
    {
        foreach ($this->translatableAttributes() as $attribute) {
            if ($row->getAttribute($attribute) !== null) {
                return false;
            }
        }

        return true;
    }
}
