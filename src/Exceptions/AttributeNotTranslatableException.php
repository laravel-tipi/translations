<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use InvalidArgumentException;

final class AttributeNotTranslatableException extends InvalidArgumentException implements TranslationException
{
    /**
     * @param  class-string  $model
     */
    public function __construct(
        public readonly string $model,
        public readonly string $attribute,
    ) {
        parent::__construct("Attribute [{$attribute}] is not translatable on model [{$model}].");
    }
}
