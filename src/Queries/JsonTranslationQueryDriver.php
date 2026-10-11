<?php

declare(strict_types=1);

namespace Tipi\Translations\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use Tipi\Translations\Contracts\TranslationQueryDriver;

final readonly class JsonTranslationQueryDriver implements TranslationQueryDriver
{
    public function where(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        string $localeCode,
        string $boolean = 'and',
    ): Builder {
        if (! in_array($boolean, ['and', 'or'], true)) {
            throw new InvalidArgumentException(
                "Unsupported query boolean [$boolean].",
            );
        }

        return $query->where(
            "$attribute->$localeCode",
            $operator,
            $value,
            $boolean,
        );
    }

    public function orderBy(
        Builder $query,
        string $attribute,
        string $direction,
        string $localeCode,
    ): Builder {
        return $query->orderBy($this->value($query, $attribute, $localeCode), $direction);
    }

    public function value(
        Builder $query,
        string $attribute,
        string $localeCode,
    ): Expression {
        $grammar = $query->getQuery()->getGrammar();

        $column = $query->getModel()->qualifyColumn(
            "$attribute->$localeCode",
        );

        return new Expression($grammar->wrap($column));
    }
}
