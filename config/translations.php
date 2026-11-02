<?php

declare(strict_types=1);

use Tipi\Translations\Models\Translation;
use Tipi\Translations\Models\TranslationState;

return [

    /*
    |--------------------------------------------------------------------------
    | Shared Table Storage
    |--------------------------------------------------------------------------
    |
    | Used only by models with the HasSharedTableTranslations trait. Publish
    | and run the "create_translations_table" migration to use it.
    |
    */

    'shared' => [
        'table' => 'translations',
        'model' => Translation::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Polymorphic Key Type
    |--------------------------------------------------------------------------
    |
    | The type of the translatable_id column of the shared translations and
    | translation states tables: "int", "uuid", "ulid", or "string" when your
    | translatable models mix key types. Null follows Laravel's default
    | morph key type (Schema::morphUsingUuids() / morphUsingUlids()).
    |
    | The migrations read it when they create the tables. Change it only
    | together with the columns.
    |
    */

    'morph_key_type' => null,

    /*
    |--------------------------------------------------------------------------
    | Translation States
    |--------------------------------------------------------------------------
    |
    | Used only by models with the HasTranslationStates trait. Publish and run
    | the "create_translation_states_table" migration to use them.
    |
    | "status_enum" is an optional backed enum for per-locale statuses (for
    | example Draft / Review / Published). Leave it null to not use statuses.
    |
    */

    'states' => [
        'table' => 'translation_states',
        'model' => TranslationState::class,
        'status_enum' => null,
    ],

];
