<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use UnexpectedValueException;

/**
 * A JSON translation column does not contain a JSON object keyed by locale.
 */
final class MalformedTranslationsException extends UnexpectedValueException implements TranslationException
{
    public function __construct(
        public readonly string $model,
        public readonly string $attribute,
    ) {
        parent::__construct(sprintf(
            'Column [%s] of model [%s] must contain a JSON object keyed by locale code.',
            $attribute,
            $model,
        ));
    }
}
