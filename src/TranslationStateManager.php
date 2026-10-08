<?php

declare(strict_types=1);

namespace Tipi\Translations;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\HasTranslationStates;
use Tipi\Translations\Models\TranslationState;

final readonly class TranslationStateManager
{
    public function get(
        Model&HasTranslationStates $translatable,
        string $localeCode,
    ): ?TranslationState {
        return $translatable->translationStates()
            ->where('locale_code', $localeCode)
            ->first();
    }

    public function create(
        Model&HasTranslationStates $translatable,
        string $localeCode,
    ): TranslationState {
        /** @var TranslationState $state */
        $state = $translatable->translationStates()->create([
            'locale_code' => $localeCode,
        ]);

        return $state;
    }

    public function delete(
        Model&HasTranslationStates $translatable,
        string $localeCode,
    ): void {
        $translatable->translationStates()
            ->where('locale_code', $localeCode)
            ->delete();
    }

    public function setStatus(
        Model&HasTranslationStates $translatable,
        string $localeCode,
        ?BackedEnum $status,
    ): TranslationState {
        $state = $this->getOrFail(
            translatable: $translatable,
            localeCode: $localeCode,
        );

        $state->status = $status;
        $state->save();

        return $state;
    }

    public function markAsOutdated(
        Model&HasTranslationStates $translatable,
        string $localeCode,
    ): TranslationState {
        $state = $this->getOrFail(
            translatable: $translatable,
            localeCode: $localeCode,
        );

        $state->outdated_at = now();
        $state->save();

        return $state;
    }

    public function markAsCurrent(
        Model&HasTranslationStates $translatable,
        string $localeCode,
    ): TranslationState {
        $state = $this->getOrFail(
            translatable: $translatable,
            localeCode: $localeCode,
        );

        $state->outdated_at = null;
        $state->save();

        return $state;
    }

    public function markOthersAsOutdated(
        Model&HasTranslationStates $translatable,
        string $localeCode,
    ): void {
        $translatable->translationStates()
            ->where('locale_code', '!=', $localeCode)
            ->update([
                'outdated_at' => now(),
            ]);
    }

    private function getOrFail(
        Model&HasTranslationStates $translatable,
        string $localeCode,
    ): TranslationState {
        /** @var TranslationState $state */
        $state = $translatable->translationStates()
            ->where('locale_code', $localeCode)
            ->firstOrFail();

        return $state;
    }
}
