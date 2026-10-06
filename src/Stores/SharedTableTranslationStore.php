<?php

declare(strict_types=1);

namespace Tipi\Translations\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Models\TranslationModel;
use Tipi\Translations\Translation;

final readonly class SharedTableTranslationStore
{
    public function get(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): ?Translation {
        /** @var TranslationModel|null $translation */
        $translation = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->first();

        if ($translation === null) {
            return null;
        }

        return $this->toTranslation(
            translatable: $translatable,
            translation: $translation,
        );
    }

    /**
     * @return Collection<int, Translation>
     */
    public function all(
        Model&SharedTableTranslatableModel $translatable,
    ): Collection {
        return $translatable->translationRecords()
            ->get()
            ->map(
                fn (TranslationModel $translation): Translation => $this->toTranslation(
                    translatable: $translatable,
                    translation: $translation,
                ),
            );
    }

    public function exists(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): bool {
        return $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->exists();
    }

    public function create(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        /** @var TranslationModel $translation */
        $translation = $translatable->translationRecords()->create([
            'locale_code' => $localeCode,
            'values' => $attributes,
        ]);

        return $this->toTranslation(
            translatable: $translatable,
            translation: $translation,
        );
    }

    public function update(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        /** @var TranslationModel $translation */
        $translation = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->firstOrFail();

        $translation->update([
            'values' => array_merge(
                $translation->values,
                $attributes,
            ),
            'outdated_at' => null,
        ]);

        return $this->toTranslation(
            translatable: $translatable,
            translation: $translation,
        );
    }

    public function markOthersAsOutdated(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): void {
        $translatable->translationRecords()
            ->where('locale_code', '!=', $localeCode)
            ->update([
                'outdated_at' => now(),
            ]);
    }

    public function delete(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): void {
        $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->delete();
    }

    private function toTranslation(
        Model&SharedTableTranslatableModel $translatable,
        TranslationModel $translation,
    ): Translation {
        return new Translation(
            translatable: $translatable,
            localeCode: $translation->locale_code,
            attributes: $translation->values,
        );
    }
}
