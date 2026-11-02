<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use Tipi\Translations\Exceptions\MalformedTranslationsException;

/**
 * Stores each translatable attribute in a JSON column on the model's own
 * table, as an object keyed by locale: {"en": "Saperavi", "ka": "საფერავი"}.
 *
 * - Reading never queries the database.
 * - Missing values are never stored: setting null removes the locale key,
 *   and a column without values is stored as NULL.
 * - Queries compare values with the connection's collation on MySQL and
 *   MariaDB, like text columns, so both storages agree.
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

    protected function writeTranslationsBeforeSave(array $changes): void
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

    protected function writeTranslationsAfterSave(array $changes): void
    {
        // Written with the model row.
    }

    protected function translationValueSql(Builder $query, string $attribute, string $locale): array
    {
        $sql = $query->getQuery()->getGrammar()->wrap($this->qualifyColumn("{$attribute}->{$locale}"));

        // MySQL and MariaDB return JSON strings in a binary collation, so
        // they would compare and sort case-sensitively, unlike text
        // columns. Use the connection's collation, as table columns do.
        $connection = $query->getConnection();
        $collation = $connection->getConfig('collation');

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)
            && is_string($collation)
            && preg_match('/^utf8mb4_[a-z0-9_]+$/', $collation) === 1) {
            $sql .= " collate {$collation}";
        }

        return [$sql, []];
    }

    protected function eagerLoadTranslations(Builder $query): void
    {
        // Translations are columns of the model: nothing to load.
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
