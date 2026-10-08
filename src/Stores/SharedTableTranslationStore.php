<?php

declare(strict_types=1);

namespace Tipi\Translations\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Models\Translation;
use Tipi\Translations\Translation as TranslationData;

final readonly class SharedTableTranslationStore
{
    public function get(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): ?TranslationData {
        /** @var (Model&Translation)|null $translation */
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
     * @return Collection<int, TranslationData>
     */
    public function all(
        Model&SharedTableTranslatableModel $translatable,
    ): Collection {
        return $translatable->translationRecords
            ->map(
                fn (Translation $translation): TranslationData => $this->toTranslation(
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
    ): TranslationData {
        /** @var Translation $translation */
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
    ): TranslationData {
        /** @var Translation $translation */
        $translation = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->firstOrFail();

        $translation->update([
            'values' => array_merge(
                $translation->values,
                $attributes,
            ),
        ]);

        $this->forgetLoadedTranslations($translatable);

        return $this->toTranslation(
            translatable: $translatable,
            translation: $translation,
        );
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
        Translation $translation,
    ): TranslationData {
        return new TranslationData(
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
