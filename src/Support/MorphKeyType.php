<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Str;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;

/**
 * The type of the translatable_id column of the package's polymorphic
 * tables (shared translations and translation states), set by
 * [translations.morph_key_type]. Null follows Laravel's default morph key
 * type (Schema::morphUsingUuids() and morphUsingUlids()).
 *
 * - int, uuid, ulid: the column has the keys' native type. Every model
 *   using the tables must have keys of that type.
 * - string: a string column, for applications whose translatable models
 *   mix integer, UUID, ULID or other string keys. Keys are stored and
 *   compared as strings.
 *
 * The migrations and the polymorphic relations both read it, so change
 * it only together with the tables' columns.
 */
enum MorphKeyType: string
{
    case Int = 'int';
    case Uuid = 'uuid';
    case Ulid = 'ulid';
    case String = 'string';

    /**
     * The longest key the string column holds.
     */
    public const int STRING_LENGTH = 64;

    public static function configured(): self
    {
        $type = config('translations.morph_key_type') ?? Builder::$defaultMorphKeyType;

        return (is_string($type) ? self::tryFrom($type) : null)
            ?? throw InvalidTranslatableModelException::invalidConfiguration(
                '[translations.morph_key_type] must be null, "int", "uuid", "ulid" or "string"',
            );
    }

    /**
     * Add the {$name}_type and {$name}_id columns, without an index: the
     * tables index them together with the locale.
     */
    public function addColumns(Blueprint $table, string $name): void
    {
        $table->string("{$name}_type");

        match ($this) {
            self::Int => $table->unsignedBigInteger("{$name}_id"),
            self::Uuid => $table->uuid("{$name}_id"),
            self::Ulid => $table->ulid("{$name}_id"),
            self::String => $table->string("{$name}_id", self::STRING_LENGTH),
        };
    }

    /**
     * Whether the model's key type fits the column. Checked when the
     * relation is built, so a mismatch fails before any query.
     */
    public function ensureSupports(Model $model): void
    {
        $integer = in_array($model->getKeyType(), ['int', 'integer'], true);

        $supported = match ($this) {
            self::Int => $integer,
            self::Uuid, self::Ulid => ! $integer,
            self::String => true,
        };

        if (! $supported) {
            throw InvalidTranslatableModelException::unsupportedKey(
                $model::class,
                "its [{$model->getKeyType()}] keys do not fit the [{$this->value}] translatable_id column",
            );
        }
    }

    /**
     * Whether the model's key value fits the column, checked when the
     * relation is built: databases would otherwise reject it, find
     * nothing, or store it silently.
     */
    public function ensureKey(Model $model): void
    {
        $key = $model->getKey();

        $valid = match ($this) {
            self::Int => is_int($key) || (is_string($key) && ctype_digit($key)),
            self::Uuid => is_string($key) && Str::isUuid($key),
            self::Ulid => is_string($key) && Str::isUlid($key),
            self::String => (is_int($key) || is_string($key)) && $key !== '' && strlen((string) $key) <= self::STRING_LENGTH,
        };

        if (! $valid) {
            $shown = is_int($key) || is_string($key) ? (string) $key : get_debug_type($key);

            throw InvalidTranslatableModelException::unsupportedKey(
                $model::class,
                "the key [{$shown}] does not fit the [{$this->value}] translatable_id column",
            );
        }
    }

    /**
     * The owner's key, as SQL comparable with the translatable_id column.
     *
     * @param  string  $column  the wrapped key column
     */
    public function ownerKeySql(Connection $connection, string $column): string
    {
        if ($this !== self::String) {
            return $column;
        }

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => "cast({$column} as char)".self::collation($connection),
            'pgsql' => "({$column})::text",
            default => "cast({$column} as text)",
        };
    }

    /**
     * MySQL casts to the connection's character set: compare in the
     * configured collation, like the tables' string columns.
     */
    private static function collation(Connection $connection): string
    {
        $collation = $connection->getConfig('collation');

        return is_string($collation) && preg_match('/^utf8mb4_[a-z0-9_]+$/', $collation) === 1 ? " collate {$collation}" : '';
    }
}
