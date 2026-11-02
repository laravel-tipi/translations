<?php

declare(strict_types=1);

namespace Tipi\Translations;

/**
 * The outcome of resolving one translated attribute for one locale, with
 * fallback. Exactly one of isTranslated(), isFallback() and isMissing() is
 * true.
 */
final readonly class ResolvedTranslation
{
    /**
     * @param  string  $requestedLocale  The locale that was asked for.
     * @param  string|null  $locale  The locale the value came from; null when missing.
     */
    public function __construct(
        public string $attribute,
        public string $requestedLocale,
        public ?string $locale,
        public mixed $value,
    ) {}

    /**
     * The value is stored in the requested locale.
     */
    public function isTranslated(): bool
    {
        return $this->locale === $this->requestedLocale;
    }

    /**
     * The value came from a fallback locale.
     */
    public function isFallback(): bool
    {
        return $this->locale !== null && $this->locale !== $this->requestedLocale;
    }

    /**
     * Neither the requested locale nor any fallback locale has a value.
     */
    public function isMissing(): bool
    {
        return $this->locale === null;
    }
}
