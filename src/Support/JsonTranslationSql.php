<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

use Illuminate\Database\Connection;
use LogicException;

/**
 * SQL for a translated JSON value, per database: one locale's member of a
 * JSON storage column, or a whole JSON value of shared storage.
 *
 * value() and document() yield the stored JSON value, or NULL when it is
 * missing (a JSON null counts as missing, like on reads). compare() and
 * order() read that value as a number or as text, so comparisons follow
 * the stored JSON type on every database.
 *
 * @internal
 */
final readonly class JsonTranslationSql
{
    private string $driver;

    public function __construct(private Connection $connection)
    {
        $this->driver = $connection->getDriverName();

        if (! in_array($this->driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new LogicException("Querying JSON translations is not supported on the [{$this->driver}] database driver.");
        }
    }

    /**
     * The value with its bindings: the locale is always bound.
     *
     * @param  string  $column  the wrapped column
     * @return array{0: string, 1: list<string>}
     */
    public function value(string $column, string $locale): array
    {
        // A JSON path with the locale as a quoted member.
        $path = '$."'.addcslashes($locale, '"\\').'"';

        return match ($this->driver) {
            // The member by its key, not by a path: older SQLite (3.46 on
            // Ubuntu 24.04) ends a quoted path label at the first `"` and
            // ignores escapes, so such a locale would match nothing.
            'sqlite' => ["(select value from json_each({$column}) where key = ?)", [$locale]],
            'mysql', 'mariadb' => ["nullif(json_extract({$column}, ?), json_extract('null', '$'))", [$path]],
            default => ["nullif(({$column})::jsonb -> (?)::text, 'null'::jsonb)", [$locale]],
        };
    }

    /**
     * The whole JSON value of a column.
     *
     * @param  string  $column  the wrapped column
     */
    public function document(string $column): string
    {
        return match ($this->driver) {
            'sqlite' => "json_extract({$column}, '$')",
            'mysql', 'mariadb' => "nullif(json_extract({$column}, '$'), json_extract('null', '$'))",
            default => "nullif(({$column})::jsonb, 'null'::jsonb)",
        };
    }

    /**
     * A condition comparing a value: numerically when the operand is
     * numeric and the stored value is a number, otherwise as text.
     *
     * @param  array{0: string, 1: list<mixed>}  $value
     * @return array{0: string, 1: list<mixed>}
     */
    public function compare(array $value, string $operator, string|int|float $operand): array
    {
        [$expression, $bindings] = $value;

        // Each use of the expression repeats its bindings.
        $text = $this->text($expression);

        if (! is_numeric($operand) || str_contains($operator, 'like')) {
            return ["{$text} {$operator} ?", [...$bindings, (string) $operand]];
        }

        return [
            "case when {$this->isNumber($expression)} then ({$this->number($expression)} {$operator} {$this->numberPlaceholder()}) else ({$text} {$operator} ?) end",
            [...$bindings, ...$bindings, is_string($operand) ? $operand + 0 : $operand, ...$bindings, (string) $operand],
        ];
    }

    /**
     * ORDER BY expressions: missing values last, numbers numerically
     * before text, text in the connection's collation.
     *
     * @param  array{0: string, 1: list<mixed>}  $value
     * @return array{0: string, 1: list<mixed>}
     */
    public function order(array $value, string $direction): array
    {
        [$expression, $bindings] = $value;
        $isNumber = $this->isNumber($expression);

        // The expression is used five times; each use repeats its bindings.
        return [
            "case when {$expression} is null then 1 else 0 end, "
                ."case when {$isNumber} then 0 else 1 end, "
                ."case when {$isNumber} then {$this->number($expression)} end {$direction}, "
                ."{$this->text($expression)} {$direction}",
            [...$bindings, ...$bindings, ...$bindings, ...$bindings, ...$bindings],
        ];
    }

    public function isNumber(string $value): string
    {
        return match ($this->driver) {
            'sqlite' => "typeof({$value}) in ('integer', 'real')",
            'mysql', 'mariadb' => "json_type({$value}) in ('INTEGER', 'UNSIGNED INTEGER', 'DOUBLE', 'DECIMAL')",
            default => "jsonb_typeof({$value}) = 'number'",
        };
    }

    public function number(string $value): string
    {
        return match ($this->driver) {
            'sqlite' => $value,
            'mysql', 'mariadb' => "cast(json_unquote({$value}) as decimal(65, 30))",
            default => "({$value} #>> '{}')::numeric",
        };
    }

    /**
     * A placeholder for a number compared with number().
     */
    public function numberPlaceholder(): string
    {
        // SQLite would compare a bound float, sent as text, as text.
        return $this->driver === 'sqlite' ? '(? + 0)' : '?';
    }

    /**
     * The value as text: strings unquoted, other values as JSON. On MySQL
     * and MariaDB in the connection's collation, so JSON values compare
     * and sort like text columns.
     */
    public function text(string $value): string
    {
        if ($this->driver === 'sqlite') {
            return "cast({$value} as text)";
        }

        if ($this->driver === 'pgsql') {
            return "({$value} #>> '{}')";
        }

        $collation = $this->connection->getConfig('collation');

        return is_string($collation) && preg_match('/^utf8mb4_[a-z0-9_]+$/', $collation) === 1
            ? "json_unquote({$value}) collate {$collation}"
            : "json_unquote({$value})";
    }
}
