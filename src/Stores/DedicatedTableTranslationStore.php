<?php

declare(strict_types=1);

namespace Tipi\Translations\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\TranslationModel;
use Tipi\Translations\Translation;

final readonly class DedicatedTableTranslationStore
{
    public function get(
        Model&DedicatedTableTranslatableModel $translatable,
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
        Model&DedicatedTableTranslatableModel $translatable,
    ): Collection {
        return $translatable->translationRecords
            ->map(
                fn (Model&TranslationModel $translation): Translation => $this->toTranslation(
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

        /** @var Model&TranslationModel $translation */
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
        /** @var Model&TranslationModel $translation */
        $translation = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->firstOrFail();

        $translation->forceFill([
            ...$attributes,
        ]);

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
    public function delete(
        Model&DedicatedTableTranslatableModel $translatable,
        string $localeCode,
    ): void {
        /** @var Model&TranslationModel $translation */
        $translation = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->firstOrFail();

        $translation->delete();

        $this->forgetLoadedTranslations($translatable);
    }

    private function toTranslation(
        Model&DedicatedTableTranslatableModel $translatable,
        Model&TranslationModel $translation,
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
