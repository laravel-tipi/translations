<?php

declare(strict_types=1);

use Tipi\Translations\Models\TranslationModel;
use Tipi\Translations\Providers\LocalizationLocaleProvider;

return [
    /*
     * The database table used for shared translations.
     */
    'translations_table' => 'translations',

    /*
     * The model used for shared translations.
     */
    'translation_model' => TranslationModel::class,

    /*
     * The locale provider implementation.
     */
    'locale_provider' => LocalizationLocaleProvider::class,
];
