<?php

declare(strict_types=1);

namespace Tipi\Translations\Queries;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Tipi\Translations\Contracts\TranslationQueryDriver;

final readonly class SharedTableTranslationQueryDriver implements TranslationQueryDriver
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
            ->where("values->$attribute", $operator, $value);

        return match ($boolean) {
            'and' => $query->whereHas('translationRecords', $callback),
            'or' => $query->orWhereHas('translationRecords', $callback),
            default => throw new InvalidArgumentException(
                "Unsupported query boolean [{$boolean}].",
            ),
        };
    }
}
