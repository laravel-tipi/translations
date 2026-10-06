<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

final class InvalidTranslationConfigurationException extends TranslationConfigurationException
{
    public static function missing(string $model, string $configuration): self
    {
        return new self(
            "Translation configuration [$configuration] is not defined for model [$model].",
        );
    }
}
