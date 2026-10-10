<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Contracts\TranslationQueryDriver;
use Tipi\Translations\Enums\TranslationDriver;
use Tipi\Translations\Queries\DedicatedTableTranslationQueryDriver;
use Tipi\Translations\Queries\JsonTranslationQueryDriver;
use Tipi\Translations\Queries\SharedTableTranslationQueryDriver;

final readonly class TranslationQueryManager
{
    public function __construct(
        private LocaleProvider $locales,
        private TranslationDriverResolver $drivers,
        private JsonTranslationQueryDriver $jsonDriver,
        private DedicatedTableTranslationQueryDriver $dedicatedTableDriver,
        private SharedTableTranslationQueryDriver $sharedTableDriver,
    ) {}

    public function where(
        Builder $query,
        string $attribute,
        string $operator,
        mixed $value,
        ?string $localeCode = null,
        string $boolean = 'and',
    ): Builder {
        $model = $this->validateModelAndAttribute($query, $attribute);

        $localeCode ??= $this->locales->current()->code;

        return $this->driver($model)->where(
            query: $query,
            attribute: $attribute,
            operator: $operator,
            value: $value,
            localeCode: $localeCode,
            boolean: $boolean,
        );
    }

    public function orderBy(
        Builder $query,
        string $attribute,
        string $direction = 'asc',
        ?string $localeCode = null,
    ): Builder {
        $model = $this->validateModelAndAttribute($query, $attribute);

        $direction = strtolower($direction);

        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException(
                "Unsupported sorting direction [$direction].",
            );
        }

        $localeCode ??= $this->locales->current()->code;

        return $this->driver($model)->orderBy(
            query: $query,
            attribute: $attribute,
            direction: $direction,
            localeCode: $localeCode,
        );
    }

    private function validateModelAndAttribute(
        Builder $query,
        string $attribute,
    ): Model&TranslatableModel {
        /** @var Model&TranslatableModel $model */
        $model = $query->getModel();

        if (! $model instanceof TranslatableModel) {
            throw new LogicException(
                'The query model must implement TranslatableModel.',
            );
        }

        if (! in_array(
            $attribute,
            $model::getTranslatableAttributes(),
            true,
        )) {
            throw new InvalidArgumentException(
                sprintf(
                    'Attribute [%s] is not translatable on model [%s].',
                    $attribute,
                    $model::class,
                ),
            );
        }

        return $model;
    }

    private function driver(
        Model&TranslatableModel $translatable,
    ): TranslationQueryDriver {
        return match ($this->drivers->resolve($translatable)) {
            TranslationDriver::Json => $this->jsonDriver,
            TranslationDriver::DedicatedTable => $this->dedicatedTableDriver,
            TranslationDriver::SharedTable => $this->sharedTableDriver,
        };
    }
}
