<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection as BaseCollection;
use JsonException;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;
use Tipi\Translations\Exceptions\MalformedTranslationsException;
use Tipi\Translations\Models\Translation;
use Tipi\Translations\Support\JsonTranslationSql;
use Tipi\Translations\Support\MorphKeyType;
use Tipi\Translations\Support\TranslatableAttributes;

/**
 * Stores translations in one table shared by every model that uses it:
 * one row per model, locale and attribute, the value as JSON.
 *
 * - The rows are the translations() relation. Eager load it
 *   (with('translations') or withTranslations()) when reading many models.
 * - Values keep their JSON type, like JSON storage; queries compare them
 *   the same way.
 * - Missing values are never stored: setting null deletes the row.
 * - Rows are deleted with the model (not on soft delete), in one
 *   transaction. There is no foreign key: see the README for deletes
 *   that bypass the model.
 * - The rows are hidden from toArray(); translated attributes are
 *   serialized instead.
 *
 * @mixin Model
 */
trait HasSharedTableTranslations
{
    use HasTranslations;

    /**
     * The loaded translations collection that $sharedTranslationValues
     * indexes.
     */
    private ?Collection $indexedSharedTranslations = null;

    /**
     * @var array<string, array<string, mixed>> locale => attribute => value
     */
    private array $sharedTranslationValues = [];

    /**
     * The rows are storage: serialize the translated attributes instead.
     * makeVisible('translations') to include them.
     */
    public function initializeHasSharedTableTranslations(): void
    {
        $this->makeHidden('translations');
    }

    /**
     * The rows of this model in the shared table.
     *
     * Do not constrain this relation when eager loading it: translations
     * are read from it, so a partial list reads as missing translations.
     *
     * @return MorphMany<Translation, $this>
     */
    public function translations(): MorphMany
    {
        return $this->translationMorphMany(Translation::modelClass());
    }

    protected function storedTranslation(string $attribute, string $locale): mixed
    {
        return $this->sharedTranslationValues()[$locale][$attribute] ?? null;
    }

    protected function storedTranslations(): array
    {
        $declared = TranslatableAttributes::of(static::class);
        $translations = [];

        foreach ($this->sharedTranslationValues() as $locale => $values) {
            // Rows of attributes no longer declared are kept, not read.
            $values = array_intersect_key($values, $declared);

            if ($values !== []) {
                $translations[$locale] = $values;
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
     * One upsert writes every set value and one delete removes every
     * cleared one. Each value is its own row, so concurrent writers of
     * different locales or attributes never touch the same row, and a
     * row inserted meanwhile is updated instead.
     *
     * The model's row is locked first, so rows are never added for a
     * model that another process deleted: that fails like a foreign key.
     */
    protected function writeTranslations(array $changes): void
    {
        $relation = $this->translations();
        $related = $relation->getRelated();

        $this->ensureTranslationConnection($related);
        $this->lockTranslationOwnerOrFail();

        $owner = [
            'translatable_type' => $relation->getMorphClass(),
            'translatable_id' => $relation->getParentKey(),
        ];

        $declared = TranslatableAttributes::of(static::class);
        $rows = [];
        $cleared = [];

        // Rows in one order, so concurrent writers lock them in one order.
        foreach ($this->translationLocales()->sort($changes) as $locale => $values) {
            $locale = (string) $locale;

            foreach (array_intersect_key(array_replace($declared, $values), $values) as $attribute => $value) {
                if (strlen($attribute) > 64) {
                    throw InvalidTranslatableModelException::invalidDeclaration(static::class, "[{$attribute}] is longer than the shared table's 64 characters");
                }

                if ($value === null) {
                    $cleared[$locale][] = $attribute;

                    continue;
                }

                $rows[] = [...$owner, 'locale_code' => $locale, 'attribute' => $attribute, 'value' => $this->encodeSharedTranslation($value)];
            }
        }

        if ($rows !== []) {
            $related->newQuery()->upsert($rows, ['translatable_type', 'translatable_id', 'locale_code', 'attribute'], ['value']);
        }

        if ($cleared !== []) {
            $this->translations()
                ->where(function (Builder $query) use ($cleared): void {
                    foreach ($cleared as $locale => $attributes) {
                        $query->orWhere(fn (Builder $query): Builder => $query->where('locale_code', $locale)->whereIn('attribute', $attributes));
                    }
                })
                ->delete();
        }

        if ($this->relationLoaded('translations')) {
            $this->setRelation('translations', $this->translations()->get());
        }
    }

    protected function translationValueSql(Builder $query, string $attribute, string $locale): array
    {
        return $this->sharedTranslationSql($query, $attribute, $locale, raw: false);
    }

    /**
     * Values compare by their JSON type, like JSON storage.
     */
    protected function compareTranslationSql(Builder $query, array $value, string $operator, string|int|float $operand): array
    {
        return (new JsonTranslationSql($query->getConnection()))->compare($value, $operator, $operand);
    }

    protected function orderTranslationSql(Builder $query, array $value, string $direction): array
    {
        return (new JsonTranslationSql($query->getConnection()))->order($value, $direction);
    }

    /**
     * Reads the stored JSON and decodes it like model reads.
     */
    protected function pluckTranslationValues(Builder $query, string $attribute, array $locales, string $key): BaseCollection
    {
        $parts = [];
        $bindings = [];

        foreach ($locales as $locale) {
            [$sql, $partBindings] = $this->sharedTranslationSql($query, $attribute, $locale, raw: true);
            $parts[] = $sql;
            array_push($bindings, ...$partBindings);
        }

        $grammar = $query->getQuery()->getGrammar();
        $value = count($parts) === 1 ? $parts[0] : 'coalesce('.implode(', ', $parts).')';

        return $query->toBase()
            ->select($key.' as tipi_translation_key')
            ->selectRaw("{$value} as ".$grammar->wrap('tipi_translation_value'), $bindings)
            ->pluck('tipi_translation_value', 'tipi_translation_key')
            ->map(fn (mixed $raw): mixed => $this->decodeSharedTranslation($attribute, $raw));
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
     * A subquery for the model's value of the attribute in the locale:
     * the JSON value (NULL when missing), or with $raw the stored JSON
     * text, for decoding in PHP.
     *
     * @param  Builder<static>  $query
     * @return array{0: string, 1: list<mixed>}
     */
    private function sharedTranslationSql(Builder $query, string $attribute, string $locale, bool $raw): array
    {
        $connection = $query->getConnection();
        $grammar = $query->getQuery()->getGrammar();
        $sql = new JsonTranslationSql($connection);
        $relation = $this->translations();
        $related = $relation->getRelated();

        $column = $grammar->wrap($related->qualifyColumn('value'));
        $owner = MorphKeyType::configured()->ownerKeySql($connection, $grammar->wrap($this->getQualifiedKeyName()));

        $value = $connection->query()
            ->from($related->getTable())
            ->selectRaw($raw ? $column : $sql->document($column))
            ->where($related->qualifyColumn('translatable_type'), $relation->getMorphClass())
            ->whereRaw($grammar->wrap($related->qualifyColumn('translatable_id'))." = {$owner}")
            ->where($related->qualifyColumn('locale_code'), $locale)
            ->where($related->qualifyColumn('attribute'), $attribute);

        if ($raw) {
            // Coalescing over locales skips missing values only.
            $value->whereRaw($sql->document($column).' is not null');
        }

        return ['('.$value->toSql().')', $value->getBindings()];
    }

    /**
     * Values of the loaded (or lazily loaded) rows.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sharedTranslationValues(): array
    {
        $rows = $this->getRelationValue('translations');

        if ($rows !== $this->indexedSharedTranslations) {
            $values = [];

            foreach ($rows as $row) {
                $attribute = (string) $row->getAttribute('attribute');
                $value = $this->decodeSharedTranslation($attribute, $row->getAttributes()['value'] ?? null);

                if ($value !== null) {
                    $values[(string) $row->getAttribute('locale_code')][$attribute] = $value;
                }
            }

            $this->sharedTranslationValues = $values;
            $this->indexedSharedTranslations = $rows;
        }

        return $this->sharedTranslationValues;
    }

    private function decodeSharedTranslation(string $attribute, mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        try {
            return json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MalformedTranslationsException(static::class, $attribute);
        }
    }

    /**
     * Encoded like JSON storage, so both store the same JSON.
     */
    private function encodeSharedTranslation(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
