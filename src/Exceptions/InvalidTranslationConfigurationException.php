<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\TranslatableModel;

final class InvalidTranslationConfigurationException extends TranslationConfigurationException
{
    public static function missing(string $model, string $configuration): self
    {
        return new self(
            "Translation configuration [$configuration] is not defined for model [$model].",
        );
    }

    public static function missingStorageStrategy(
        Model&TranslatableModel $model,
    ): self {
        return new self(sprintf(
            'Translatable model [%s] does not define a supported translation storage strategy.',
            $model::class,
        ));
    }

    public static function multipleStorageStrategies(
        Model&TranslatableModel $model,
    ): self {
        return new self(sprintf(
            'Translatable model [%s] defines multiple translation storage strategies.',
            $model::class,
        ));
    }
}
