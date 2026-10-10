<?php

declare(strict_types=1);

namespace Tipi\Translations\Queries;

use Illuminate\Database\Eloquent\Builder;
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
        $grammar = $query->getQuery()->getGrammar();

        $column = $query->getModel()->qualifyColumn(
            "$attribute->$localeCode",
        );

        $expression = $grammar->wrap($column);

        return $query->orderByRaw(
            "$expression $direction",
        );
    }
}
