<?php

declare(strict_types=1);

namespace Tipi\Translations\Config;

use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Models\TranslationModel;

final readonly class TranslationConfig
{
    /**
     * @param  class-string<TranslationModel>  $translationModel
     * @param  class-string<LocaleProvider>  $localeProvider
     */
    public function __construct(
        public string $translationsTable,
        public string $translationModel,
        public string $localeProvider,
    ) {}
}
