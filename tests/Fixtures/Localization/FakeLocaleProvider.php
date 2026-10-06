<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Localization;

use Illuminate\Support\Collection;
use Tipi\Localization\Enums\TextDirection;
use Tipi\Localization\Locale;
use Tipi\Translations\Contracts\LocaleProvider;

final class FakeLocaleProvider implements LocaleProvider
{
    private string $currentCode = 'en';

    private string $defaultCode = 'en';

    /**
     * @var Collection<string, Locale>
     */
    private Collection $locales;

    public function __construct()
    {
        $this->locales = collect([
            'en' => new Locale(
                code: 'en',
                name: 'English',
                nativeName: 'English',
                countryCode: 'GB',
                textDirection: TextDirection::Ltr,
                active: true,
                default: true,
            ),
            'ka' => new Locale(
                code: 'ka',
                name: 'Georgian',
                nativeName: 'ქართული',
                countryCode: 'GE',
                textDirection: TextDirection::Ltr,
                active: true,
                default: false,
            ),
            'de' => new Locale(
                code: 'de',
                name: 'German',
                nativeName: 'Deutsch',
                countryCode: 'DE',
                textDirection: TextDirection::Ltr,
                active: true,
                default: false,
            ),
        ]);
    }

    /**
     * @return Collection<string, Locale>
     */
    public function supported(): Collection
    {
        return $this->locales;
    }

    public function current(): Locale
    {
        return $this->supportedLocale($this->currentCode);
    }

    public function default(): Locale
    {
        return $this->supportedLocale($this->defaultCode);
    }

    public function supportedLocale(string $code): Locale
    {
        return $this->locales->get($code)
            ?? throw new \InvalidArgumentException(
                sprintf('Unsupported locale [%s].', $code),
            );
    }

    public function setCurrent(string $code): self
    {
        $this->supportedLocale($code);

        $this->currentCode = $code;

        return $this;
    }

    public function setDefault(string $code): self
    {
        $this->supportedLocale($code);

        $this->defaultCode = $code;

        return $this;
    }
}
