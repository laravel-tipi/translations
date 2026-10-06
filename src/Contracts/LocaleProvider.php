<?php

declare(strict_types=1);

namespace Tipi\Translations\Contracts;

use Illuminate\Support\Collection;
use Tipi\Support\Locale;

interface LocaleProvider
{
    /**
     * @return Collection<string, Locale>
     */
    public function supported(): Collection;

    public function current(): Locale;

    public function default(): Locale;

    public function supportedLocale(string $code): Locale;
}
