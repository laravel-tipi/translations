<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use LogicException;

final class InvalidTranslationAttributeException extends LogicException
{
    /**
     * @param array<int, string> $attributes
     */
    public static function forAttributes(
        string $model,
        array $attributes,
    ): self {
        return new self(sprintf(
            'Attributes [%s] are not translatable on model [%s].',
            implode(', ', $attributes),
            $model,
        ));
    }
}
