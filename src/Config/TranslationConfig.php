<?php

declare(strict_types=1);

namespace Tipi\Translations\Config;

use Tipi\Translations\Contracts\LocaleProvider;

final readonly class TranslationConfig
{
    public function __construct(
        public string $translationsTable,
        public string $translationModel,
        public LocaleProvider $localeProvider,
    ) {}
}
