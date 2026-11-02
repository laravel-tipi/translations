<?php

declare(strict_types=1);

namespace Tipi\Translations\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A translation was required (getTranslationOrFail) but has no value.
 *
 * This is a domain exception, not an HTTP one: the application decides
 * whether a missing translation means a 404, a redirect or something else.
 */
final class MissingTranslationException extends RuntimeException implements TranslationException
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        public readonly string $model,
        public readonly mixed $key,
        public readonly string $attribute,
        public readonly string $locale,
        public readonly bool $fallback,
    ) {
        parent::__construct(sprintf(
            'Translation [%s] for locale [%s] is missing on model [%s]%s%s.',
            $attribute,
            $locale,
            $model,
            $key === null ? '' : " with key [{$key}]",
            $fallback ? ', including its fallback locales' : '',
        ));
    }
}
