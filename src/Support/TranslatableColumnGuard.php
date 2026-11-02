<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Tipi\Translations\Exceptions\TranslatableColumnQueryException;

/**
 * Rejects where and order clauses that use a translatable attribute as a
 * column: they would compare a JSON map (JSON storage) or a column that
 * does not exist (table storage), and firstOrCreate() would silently
 * create duplicates. JSON paths ("name->en") are left alone.
 *
 * Registered as a global scope, so it sees every clause just before the
 * query runs. withoutGlobalScope(TranslatableColumnGuard::class) skips it.
 */
final class TranslatableColumnGuard implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $attributes = TranslatableAttributes::of($model::class);
        $table = $model->getTable();

        $this->guard($builder->getQuery(), $attributes, $table, $model::class);
    }

    /**
     * @param  array<string, true>  $attributes
     * @param  class-string  $model
     */
    private function guard(QueryBuilder $query, array $attributes, string $table, string $model): void
    {
        foreach ($query->wheres as $where) {
            if (($where['type'] ?? null) === 'Nested') {
                $this->guard($where['query'], $attributes, $table, $model);

                continue;
            }

            if (str_starts_with((string) ($where['type'] ?? ''), 'Json')) {
                continue;
            }

            foreach (['column', 'first', 'second'] as $key) {
                $this->check($where[$key] ?? null, $attributes, $table, $model);
            }
        }

        foreach ($query->orders ?? [] as $order) {
            $this->check($order['column'] ?? null, $attributes, $table, $model);
        }
    }

    /**
     * @param  array<string, true>  $attributes
     * @param  class-string  $model
     */
    private function check(mixed $column, array $attributes, string $table, string $model): void
    {
        if (! is_string($column)) {
            return;
        }

        $name = str_starts_with($column, "{$table}.") ? substr($column, strlen($table) + 1) : $column;

        if (isset($attributes[$name])) {
            throw new TranslatableColumnQueryException($model, $name);
        }
    }
}
