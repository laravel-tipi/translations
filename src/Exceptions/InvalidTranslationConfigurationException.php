<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use LogicException;

final class InvalidTranslationConfigurationException extends LogicException
{
    public static function missing(string $model, string $configuration): self
    {
        return new self(
            "Translation configuration [$configuration] is not defined for model [$model].",
        );
    }
}
