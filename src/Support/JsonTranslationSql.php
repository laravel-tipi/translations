<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

use Illuminate\Database\Connection;
use LogicException;

/**
 * SQL for one locale's value in a JSON translations column, per database.
 *
 * value() yields the stored JSON value, or NULL when the locale is missing
 * (a JSON null counts as missing, like on reads). The other methods read
 * that value as a number or as text, so comparisons follow the stored
 * JSON type on every database.
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
            'sqlite' => ["json_extract({$column}, ?)", [$path]],
            'mysql', 'mariadb' => ["nullif(json_extract({$column}, ?), json_extract('null', '$'))", [$path]],
            default => ["nullif(({$column})::jsonb -> (?)::text, 'null'::jsonb)", [$locale]],
        };
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
