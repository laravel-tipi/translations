<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Tipi\Translations\TranslationQueryManager;

trait HasTranslationQueries
{
    #[Scope]
    protected function whereTranslation(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        ?string $localeCode = null,
    ): Builder {
        return app(TranslationQueryManager::class)->where(
            query: $query,
            attribute: $attribute,
            operator: $operator,
            value: $value,
            localeCode: $localeCode,
        );
    }

    #[Scope]
    protected function orWhereTranslation(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        ?string $localeCode = null,
    ): Builder {
        return app(TranslationQueryManager::class)->where(
            query: $query,
            attribute: $attribute,
            operator: $operator,
            value: $value,
            localeCode: $localeCode,
            boolean: 'or',
        );
    }
}
