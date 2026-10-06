<?php

declare(strict_types=1);

namespace Tipi\Translations\Providers;

use Illuminate\Support\Collection;
use Tipi\Localization\Locale;
use Tipi\Localization\LocaleRegistry;
use Tipi\Localization\LocaleResolver;
use Tipi\Translations\Contracts\LocaleProvider;

final readonly class LocalizationLocaleProvider implements LocaleProvider
{
    public function __construct(
        private LocaleResolver $localeResolver,
        private LocaleRegistry $localeRegistry,
    ) {}

    /**
     * @return Collection<string, Locale>
     */
    public function supported(): Collection
    {
        return $this->localeRegistry->supported();
    }

    public function current(): Locale
    {
        return $this->localeResolver->current();
    }

    public function default(): Locale
    {
        return $this->localeRegistry->default();
    }

    public function supportedLocale(string $code): Locale
    {
        return $this->localeRegistry->supportedLocale($code);
    }
}
