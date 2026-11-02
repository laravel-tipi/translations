<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Tipi\Localization\LocaleContexts;
use Tipi\Localization\LocaleRegistry;
use Tipi\Translations\Contracts\FallbackPolicy;

/**
 * The default fallback policy:
 *
 * 1. the current locale context's default locale, then
 * 2. the global default locale.
 *
 * The context default is the language the current interface is meant to
 * be read in (an admin panel may default to Georgian while the site
 * defaults to English). The global default is the authoring language, and
 * Localization guarantees every context supports it. Both are always
 * active, so fallback never shows content in a disabled locale.
 */
final readonly class ContextFallbackPolicy implements FallbackPolicy
{
    public function __construct(
        private LocaleContexts $contexts,
        private LocaleRegistry $locales,
    ) {}

    public function fallbacksFor(string $locale): array
    {
        $fallbacks = [];

        foreach ([$this->contexts->current()->defaultCode(), $this->locales->defaultCode()] as $code) {
            if ($code !== $locale && ! in_array($code, $fallbacks, true)) {
                $fallbacks[] = $code;
            }
        }

        return $fallbacks;
    }
}
