<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Tipi\Localization\Exceptions\LocaleNotFoundException;
use Tipi\Localization\LocaleRegistry;
use Tipi\Localization\Localization;
use Tipi\Support\Locale;
use Tipi\Translations\Contracts\FallbackPolicy;

/**
 * The package's only view of Localization: the current locale, locale
 * validation, display order and the fallback chain.
 *
 * Scoped, like the Localization services it reads.
 *
 * @internal
 */
final readonly class TranslationLocales
{
    public function __construct(
        private Localization $localization,
        private LocaleRegistry $registry,
        private FallbackPolicy $fallback,
    ) {}

    /**
     * The application's current locale (Localization::current()): it
     * follows App::setLocale() and App::withLocale().
     */
    public function current(): string
    {
        return $this->localization->currentCode();
    }

    /**
     * The code of an explicitly given locale, or the current locale. Any
     * locale that exists in the global registry is accepted, active or
     * not, so content can be prepared before a locale is activated.
     *
     * @throws LocaleNotFoundException
     */
    public function code(string|Locale|null $locale): string
    {
        if ($locale === null) {
            return $this->current();
        }

        return $this->registry->get($locale instanceof Locale ? $locale->code : $locale)->code;
    }

    /**
     * @return list<string>
     */
    public function fallbacks(string $locale): array
    {
        return $this->fallback->fallbacksFor($locale);
    }

    /**
     * Sort locale-keyed data in the registry's display order. Codes the
     * registry does not know (data for a removed locale) come last.
     *
     * @template T
     *
     * @param  array<string, T>  $items
     * @return array<string, T>
     */
    public function sort(array $items): array
    {
        if (count($items) < 2) {
            return $items;
        }

        $sorted = [];

        foreach ($this->registry->all()->keys() as $code) {
            if (array_key_exists($code, $items)) {
                $sorted[$code] = $items[$code];
            }
        }

        return $sorted + $items;
    }
}
