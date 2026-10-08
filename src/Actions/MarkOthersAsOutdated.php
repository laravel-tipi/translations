<?php

declare(strict_types=1);

namespace Tipi\Translations\Actions;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\HasTranslationStates;
use Tipi\Translations\TranslationStateManager;

final readonly class MarkOthersAsOutdated
{
    public function __construct(
        private TranslationStateManager $translationStates,
    ) {}

    public function execute(
        Model&HasTranslationStates $translatable,
        string $localeCode,
    ): void {
        $this->translationStates->markOthersAsOutdated(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }
}
