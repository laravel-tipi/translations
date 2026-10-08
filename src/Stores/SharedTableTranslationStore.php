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
        /** @var (Model&TranslationModel)|null $translation */
        $translation = $translatable->translationRecords
            ->firstWhere('locale_code', $localeCode);

        return $translation === null
            ? null
            : $this->toTranslation(
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
        return $translatable->translationRecords
            ->map(
                fn (TranslationModel $translation): Translation => $this->toTranslation(
                    translatable: $translatable,
                    translation: $translation,
                ),
            )
            ->values();
    }

    public function exists(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): bool {
        return $translatable->translationRecords
            ->contains('locale_code', $localeCode);
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

        $this->forgetLoadedTranslations($translatable);

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

        $this->forgetLoadedTranslations($translatable);

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

        $this->forgetLoadedTranslations($translatable);
    }

    public function delete(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): void {
        $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->delete();

        $this->forgetLoadedTranslations($translatable);
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

    private function forgetLoadedTranslations(
        Model $translatable,
    ): void {
        $translatable->unsetRelation('translationRecords');
    }
}
