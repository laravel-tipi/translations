<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use InvalidArgumentException;

final class EmptyTranslationException extends InvalidArgumentException
{
    public static function create(): self
    {
        return new self(
            'A translation must contain at least one translatable attribute.',
        );
    }
}
