<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

final class TranslationAlreadyExistsException extends TranslationException
{
    public function __construct(string $code)
    {
        parent::__construct(
            "A translation for [$code] already exists.",
        );
    }
}
