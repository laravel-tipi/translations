<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

/**
 * Decides which locales are tried, in order, when a translation is missing
 * in the requested locale.
 *
 * The default policy (ContextFallbackPolicy) tries the current context's
 * default locale, then the global default locale. Bind your own
 * implementation in the container to change it for the whole application.
 */
interface FallbackPolicy
{
    /**
     * Locale codes to try after the requested locale, in order. Must not
     * contain the requested locale itself.
     *
     * @return list<string>
     */
    public function fallbacksFor(string $locale): array;
}
