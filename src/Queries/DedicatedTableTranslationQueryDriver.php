<?php

declare(strict_types=1);

namespace Tipi\Translations\Queries;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Tipi\Translations\Contracts\TranslationQueryDriver;

final readonly class DedicatedTableTranslationQueryDriver implements TranslationQueryDriver
{
    public function where(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        string $localeCode,
        string $boolean = 'and',
    ): Builder {
        $callback = fn (Builder $translationQuery): Builder => $translationQuery
            ->where('locale_code', $localeCode)
            ->where($attribute, $operator, $value);

        return match ($boolean) {
            'and' => $query->whereHas('translationRecords', $callback),
            'or' => $query->orWhereHas('translationRecords', $callback),
            default => throw new InvalidArgumentException(
                "Unsupported query boolean [$boolean].",
            ),
        };
    }

    public function orderBy(
        Builder $query,
        string $attribute,
        string $direction,
        string $localeCode,
    ): Builder {
        $relation = $query->getModel()->translationRecords();

        $translation = $relation->getRelated();

        return $query->orderBy(
            $translation->newQuery()
                ->select($attribute)
                ->whereColumn(
                    $relation->getQualifiedForeignKeyName(),
                    $query->getModel()->getQualifiedKeyName(),
                )
                ->where('locale_code', $localeCode)
                ->limit(1),
            $direction,
        );
    }
}
