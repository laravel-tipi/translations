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
        $model = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->first();

        return $model === null
            ? null
            : $this->toTranslation($translatable, $model);
    }

    /**
     * @return Collection<int, Translation>
     */
    public function all(
        Model&DedicatedTableTranslatableModel $translatable,
    ): Collection {
        return $translatable->translationRecords()
            ->get()
            ->map(
                fn (Model&TranslationModelContract $translation): Translation => $this->toTranslation(
                    $translatable,
                    $translation,
                ),
            );
    }

    public function exists(
        Model&DedicatedTableTranslatableModel $translatable,
        string $localeCode,
    ): bool {
        return $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->exists();
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
        /** @var Model&TranslationModelContract $translation */
        $translation = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->firstOrFail();

        $translation->delete();
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
}
