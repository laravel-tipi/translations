<?php

declare(strict_types=1);

namespace Tipi\Translations\Config;

use BackedEnum;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Models\Translation;

final readonly class TranslationConfig
{
    /**
     * @param  class-string<Translation>  $translationModel
     * @param  class-string<LocaleProvider>  $localeProvider
     */
    public function __construct(
        public string $translationsTable,
        public string $translationStatesTable,
        public string $translationModel,
        public string $translationStateModel,
        /**
         * @var class-string<BackedEnum>|null
         */
        public ?string $translationStateStatusEnum,
        public string $localeProvider,
    ) {}
}
