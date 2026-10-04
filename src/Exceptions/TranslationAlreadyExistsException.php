<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use RuntimeException;

final class TranslationAlreadyExistsException extends RuntimeException
{
    public function __construct(string $code)
    {
        parent::__construct(
            "A translation for [$code] already exists.",
        );
    }
}
