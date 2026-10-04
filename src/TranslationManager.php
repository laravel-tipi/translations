<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Contracts\TranslationStore;
use Tipi\Translations\Stores\DedicatedTableTranslationStore;
use Tipi\Translations\Stores\JsonTranslationStore;
use Tipi\Translations\Stores\SharedTableTranslationStore;

final readonly class TranslationManager
{
    public function __construct(
        private JsonTranslationStore $jsonStore,
        private DedicatedTableTranslationStore $dedicatedTableStore,
        private SharedTableTranslationStore $sharedTableStore,
    ) {}

    public function get(
        Model&TranslatableModel $translatable,
        string $localeCode,
    ): ?Translation {
        return $this->store($translatable)->get(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    public function exists(
        Model&TranslatableModel $translatable,
        string $localeCode,
    ): bool {
        return $this->store($translatable)->exists(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    private function store(
        Model&TranslatableModel $translatable,
    ): TranslationStore {
        return match (true) {
            $translatable instanceof JsonTranslatableModel => $this->jsonStore,
            $translatable instanceof DedicatedTableTranslatableModel => $this->dedicatedTableStore,
            $translatable instanceof SharedTableTranslatableModel => $this->sharedTableStore,
        };
    }
}
