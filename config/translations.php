<?php

declare(strict_types=1);

use Tipi\Translations\Models\TranslationState;

return [

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
