<?php

declare(strict_types=1);

namespace Tipi\Translations\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Throwable;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Contracts\TranslationModelContract;
use Tipi\Translations\Translation;

class SharedTableTranslationStore
{
    public function get(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): ?Translation {
        $translations = $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->get();

        if ($translations->isEmpty()) {
            return null;
        }

        return $this->toTranslation(
            translatable: $translatable,
            localeCode: $localeCode,
            translations: $translations,
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
            ->groupBy('locale_code')
            ->map(
                fn (Collection $translations, string $localeCode): Translation => $this->toTranslation(
                    translatable: $translatable,
                    localeCode: $localeCode,
                    translations: $translations,
                ),
            )
            ->values();
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
        foreach ($attributes as $field => $value) {
            $translatable->translationRecords()->create([
                'locale_code' => $localeCode,
                'field_name' => $field,
                'field_value' => $value,
            ]);
        }

        return $this->get($translatable, $localeCode);
    }

    /**
     * @throws Throwable
     */
    public function update(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        foreach ($attributes as $field => $value) {
            $translatable->translationRecords()->updateOrCreate(
                [
                    'locale_code' => $localeCode,
                    'field_name' => $field,
                ],
                [
                    'field_value' => $value,
                    'outdated_at' => null,
                ],
            );
        }

        return $this->get($translatable, $localeCode);
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

    /**
     * @throws Throwable
     */
    public function delete(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
    ): void {
        /** @var Model&TranslationModelContract $translation */
        $translatable->translationRecords()
            ->where('locale_code', $localeCode)
            ->delete();
    }

    private function toTranslation(
        Model&SharedTableTranslatableModel $translatable,
        string $localeCode,
        Collection $translations,
    ): Translation {
        return new Translation(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $translations
                ->pluck('field_value', 'field_name')
                ->all(),
        );
    }
}
