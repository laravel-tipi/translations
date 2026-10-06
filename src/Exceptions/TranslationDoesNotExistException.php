<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

final class TranslationDoesNotExistException extends TranslationException
{
    public function __construct(string $code)
    {
        parent::__construct(
            sprintf(
                'Translation for locale [%s] does not exist.',
                $code,
            ),
        );
    }
}
