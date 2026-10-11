<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;

interface TranslationQueryDriver
{
    public function value(
        Builder $query,
        string $attribute,
        string $localeCode,
    ): QueryBuilder|Expression;

    public function where(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        string $localeCode,
        string $boolean = 'and',
    ): Builder;

    public function orderBy(
        Builder $query,
        string $attribute,
        string $direction,
        string $localeCode,
    ): Builder;
}
