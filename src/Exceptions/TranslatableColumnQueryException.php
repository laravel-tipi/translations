<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use LogicException;

/**
 * A query compared or sorted a translatable attribute as if it were a
 * column of the model's table.
 */
final class TranslatableColumnQueryException extends LogicException implements TranslationException
{
    /**
     * @param  class-string  $model
     */
    public function __construct(
        public readonly string $model,
        public readonly string $attribute,
    ) {
        parent::__construct("Cannot query the translatable attribute [{$attribute}] of [{$model}] as a column. Use whereTranslation() or orderByTranslation().");
    }
}
