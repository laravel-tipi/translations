<?php

declare(strict_types=1);

namespace Tipi\Translations\Config;

/**
 * Exists only so migrations published by 0.1 keep running: they read the
 * table names from it. Those migrations are part of your migration
 * history, so they must run on fresh databases (tests, CI, new
 * environments) without being edited. Don't use this class in new code.
 *
 * The names follow the 0.2 configuration, so the tables the old
 * migrations create are the ones the package uses.
 */
final readonly class TranslationConfig
{
    public string $translationsTable;

    public string $translationStatesTable;

    public function __construct()
    {
        $this->translationsTable = (string) config('translations.shared.table', 'translations');
        $this->translationStatesTable = (string) config('translations.states.table', 'translation_states');
    }
}
