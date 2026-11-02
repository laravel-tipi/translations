<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;
use Tipi\Translations\Exceptions\MalformedTranslationsException;
use Tipi\Translations\Support\JsonTranslationSql;

/**
 * Stores each translatable attribute in a JSON column on the model's own
 * table, as an object keyed by locale: {"en": "Saperavi", "ka": "საფერავი"}.
 *
 * - Reading never queries the database.
 * - Missing values are never stored: setting null removes the locale key,
 *   and a column without values is stored as NULL.
 * - Queries compare by the stored JSON type: numbers as numbers, other
 *   values as text in the connection's collation, like table columns.
 * - Saving merges the changed locales into a freshly locked copy of the
 *   columns, so concurrent edits of different locales are not lost.
 *
 * Do not add casts for the translatable columns.
 *
 * @mixin Model
 */
trait HasJsonTranslations
{
    use HasTranslations;

    /**
     * attribute => [raw column value, decoded translations]
     *
     * @var array<string, array{0: mixed, 1: array<string, mixed>}>
     */
    private array $decodedTranslations = [];

    protected function storedTranslation(string $attribute, string $locale): mixed
    {
        return $this->decodedTranslations($attribute)[$locale] ?? null;
    }

    protected function storedTranslations(): array
    {
        $translations = [];

        foreach ($this->getTranslatableAttributes() as $attribute) {
            foreach ($this->decodedTranslations($attribute) as $locale => $value) {
                if ($value !== null) {
                    $translations[$locale][$attribute] = $value;
                }
            }
        }

        return $translations;
    }

    protected function translationIsLoaded(string $attribute): bool
    {
        return ! $this->exists || array_key_exists($attribute, $this->attributes);
    }

    protected function prepareTranslationWrite(array $changes): void
    {
        $attributes = [];

        foreach ($changes as $values) {
            foreach (array_keys($values) as $attribute) {
                $attributes[$attribute] = true;
            }
        }

        $attributes = array_keys($attributes);

        // Lock the row and start from the stored columns, so locales other
        // processes changed since this model was loaded are kept.
        $stored = $this->exists
            ? $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase()->lockForUpdate()->first($attributes)
            : null;

        foreach ($attributes as $attribute) {
            $translations = $stored !== null
                ? $this->decodeTranslations($attribute, $stored->{$attribute})
                : $this->decodedTranslations($attribute);

            foreach ($changes as $locale => $values) {
                if (! array_key_exists($attribute, $values)) {
                    continue;
                }

                if ($values[$attribute] === null) {
                    unset($translations[$locale]);
                } else {
                    $translations[$locale] = $values[$attribute];
                }
            }

            $this->attributes[$attribute] = $this->encodeTranslations($translations);
        }
    }

    protected function writeTranslations(array $changes): void
    {
        // Written with the model row.
    }

    protected function translationValueSql(Builder $query, string $attribute, string $locale): array
    {
        $column = $query->getQuery()->getGrammar()->wrap($this->qualifyColumn($attribute));

        return $this->jsonTranslationSql($query)->value($column, $locale);
    }

    /**
     * Values compare by their JSON type: numbers numerically when the
     * operand is numeric, everything else as text.
     */
    protected function compareTranslationSql(Builder $query, array $value, string $operator, string|int|float $operand): array
    {
        $sql = $this->jsonTranslationSql($query);
        [$expression, $bindings] = $value;

        // Each use of the expression repeats its bindings.
        $text = [$sql->text($expression), $bindings];

        if (! is_numeric($operand) || str_contains($operator, 'like')) {
            return ["{$text[0]} {$operator} ?", [...$text[1], (string) $operand]];
        }

        $isNumber = [$sql->isNumber($expression), $bindings];
        $number = [$sql->number($expression), $bindings];

        return [
            "case when {$isNumber[0]} then ({$number[0]} {$operator} {$sql->numberPlaceholder()}) else ({$text[0]} {$operator} ?) end",
            [...$isNumber[1], ...$number[1], is_string($operand) ? $operand + 0 : $operand, ...$text[1], (string) $operand],
        ];
    }

    /**
     * Numbers sort numerically before text, which sorts in the
     * connection's collation.
     */
    protected function orderTranslationSql(Builder $query, array $value, string $direction): array
    {
        $sql = $this->jsonTranslationSql($query);
        [$expression, $bindings] = $value;
        $isNumber = $sql->isNumber($expression);

        // The expression is used five times; each use repeats its bindings.
        return [
            "case when {$expression} is null then 1 else 0 end, "
                ."case when {$isNumber} then 0 else 1 end, "
                ."case when {$isNumber} then {$sql->number($expression)} end {$direction}, "
                ."{$sql->text($expression)} {$direction}",
            [...$bindings, ...$bindings, ...$bindings, ...$bindings, ...$bindings],
        ];
    }

    /**
     * Reads the columns and resolves them like model reads.
     */
    protected function pluckTranslationValues(Builder $query, string $attribute, array $locales, string $key): Collection
    {
        $rows = $query->toBase()
            ->select([$key.' as tipi_translation_key', $this->qualifyColumn($attribute).' as tipi_translation_value'])
            ->get();

        return $rows->mapWithKeys(function (object $row) use ($attribute, $locales): array {
            $translations = $this->decodeTranslations($attribute, $row->tipi_translation_value);
            $value = null;

            foreach ($locales as $locale) {
                if (($translations[$locale] ?? null) !== null) {
                    $value = $translations[$locale];

                    break;
                }
            }

            return [$row->tipi_translation_key => $value];
        });
    }

    protected function eagerLoadTranslations(Builder $query): void
    {
        // Translations are columns of the model: nothing to load.
    }

    private function jsonTranslationSql(Builder $query): JsonTranslationSql
    {
        return new JsonTranslationSql($query->getConnection());
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedTranslations(string $attribute): array
    {
        if (! array_key_exists($attribute, $this->attributes)) {
            if ($this->exists && ! $this->wasRecentlyCreated && static::preventsAccessingMissingAttributes()) {
                throw new MissingAttributeException($this, $attribute);
            }

            return [];
        }

        $raw = $this->attributes[$attribute];
        $cached = $this->decodedTranslations[$attribute] ?? null;

        if ($cached !== null && $cached[0] === $raw) {
            return $cached[1];
        }

        $translations = $this->decodeTranslations($attribute, $raw);

        $this->decodedTranslations[$attribute] = [$raw, $translations];

        return $translations;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeTranslations(string $attribute, mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            try {
                $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new MalformedTranslationsException(static::class, $attribute);
            }
        }

        if ($decoded === null) {
            return [];
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new MalformedTranslationsException(static::class, $attribute);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $translations
     */
    private function encodeTranslations(array $translations): ?string
    {
        if ($translations === []) {
            return null;
        }

        return json_encode(
            $this->translationLocales()->sort($translations),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }
}
