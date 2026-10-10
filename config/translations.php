<?php

declare(strict_types=1);

use Tipi\Translations\Models\Translation;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\Providers\LocalizationLocaleProvider;

return [
    /*
     * The database table used for shared translations.
     */
    'translations_table' => 'translations',

    'translation_states_table' => 'translation_states',

    /*
     * The model used for shared translations.
     */
    'translation_model' => Translation::class,

    'translation_state_model' => TranslationState::class,

    'translation_state_status_enum' => null,

    /*
     * The locale provider implementation.
     */
    'locale_provider' => LocalizationLocaleProvider::class,
];
