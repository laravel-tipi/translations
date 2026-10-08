<?php

declare(strict_types=1);

namespace Tipi\Translations\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\TranslationModelContract;
use Tipi\Translations\Translation;

final readonly class DedicatedTableTranslationStore
{
    public function get(
        Model&DedicatedTableTranslatableModel $translatable,
        string $localeCode,
    ): ?Translation {
        /** @var (Model&TranslationModelContract)|null $translation */
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
        Model&DedicatedTableTranslatableModel $translatable,
    ): Collection {
        return $translatable->translationRecords
            ->map(
                fn (Model&TranslationModelContract $translation): Translation => $this->toTranslation(
                    $translatable,
                    $translation,
                ),
            )
            ->values();
    }

    public function exists(
        Model&DedicatedTableTranslatableModel $translatable,
        string $localeCode,
    ): bool {
        return $translatable->translationRecords
            ->contains('locale_code', $localeCode);
    }

    public function create(
        Model&DedicatedTableTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        $translationModelClass = $translatable::getTranslationModelClass();

        /** @var Model&TranslationModelContract $translation */
        $translation = new $translationModelClass;

        $translation->forceFill([
            ...$attributes,
            'locale_code' => $localeCode,
        ]);

        $translation
            ->translatable()
            ->associate($translatable);

        $translation->save();

        $this->forgetLoadedTranslations($translatable);

        return $this->toTranslation(
            $translatable,
            $translation,
        );
    }

    /**
     * @throws Throwable
     */
    public function update(
        Model&DedicatedTableTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        /** @var Model&TranslationModelContract $translation */
        $translation = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->firstOrFail();

        $translation->forceFill([
            ...$attributes,
            'outdated_at' => null,
        ]);

        $translation->save();

        $this->forgetLoadedTranslations($translatable);

        return $this->toTranslation(
            $translatable,
            $translation,
        );
    }

    public function markOthersAsOutdated(
        Model&DedicatedTableTranslatableModel $translatable,
        string $localeCode,
    ): void {
        $translatable->translationRecords()
            ->where('locale_code', '!=', $localeCode)
            ->update([
                'outdated_at' => now(),
            ]);

        $this->forgetLoadedTranslations($translatable);
    }

    /**
     * @throws Throwable
     */
    public function delete(
        Model&DedicatedTableTranslatableModel $translatable,
        string $localeCode,
    ): void {
        /** @var Model&TranslationModelContract $translation */
        $translation = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->firstOrFail();

        $translation->delete();

        $this->forgetLoadedTranslations($translatable);
    }

    private function toTranslation(
        Model&DedicatedTableTranslatableModel $translatable,
        Model&TranslationModelContract $translation,
    ): Translation {
        $attributes = [];

        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $attributes[$attribute] = $translation->getAttribute($attribute);
        }

        return new Translation(
            translatable: $translatable,
            localeCode: $translation->getAttribute('locale_code'),
            attributes: $attributes,
        );
    }

    private function forgetLoadedTranslations(
        Model $translatable,
    ): void {
        $translatable->unsetRelation('translationRecords');
    }
}
