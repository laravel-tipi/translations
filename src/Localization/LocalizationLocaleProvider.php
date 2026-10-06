<?php

declare(strict_types=1);

namespace Tipi\Translations\Localization;

use Illuminate\Support\Collection;
use Tipi\Localization\Locale;
use Tipi\Localization\LocaleRegistry;
use Tipi\Localization\LocaleResolver;
use Tipi\Translations\Contracts\LocaleProvider;

final readonly class LocalizationLocaleProvider implements LocaleProvider
{
    public function __construct(
        private LocaleRegistry $locales,
        private LocaleResolver $localeResolver,
    ) {
    }

    public function supported(): Collection
    {
        return $this->locales->supported();
    }

    public function current(): Locale
    {
        return $this->localeResolver->current();
    }
    
    public function default(): Locale
    {
        return $this->locales->default();
    }

    public function supportedLocale(string $code): Locale
    {
        return $this->locales->supportedLocale($code);
    }
}