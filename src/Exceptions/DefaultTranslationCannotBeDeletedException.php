<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

final class DefaultTranslationCannotBeDeletedException extends TranslationException
{
    public function __construct(string $code)
    {
        parent::__construct(sprintf(
            'The default translation [%s] cannot be deleted independently. Delete the translatable model instead.',
            $code,
        ));
    }
}