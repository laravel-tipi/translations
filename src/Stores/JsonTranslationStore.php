<?php

declare(strict_types=1);

namespace Tipi\Translations\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Translation;

final readonly class JsonTranslationStore
{
    public function get(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
    ): ?Translation {
        $attributes = $this->attributes(
            translatable: $translatable,
            localeCode: $localeCode,
        );

        if ($attributes === []) {
            return null;
        }

        return new Translation(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );
    }

    /**
     * @return Collection<int, Translation>
     */
    public function all(
        Model&JsonTranslatableModel $translatable,
    ): Collection {
        $localeCodes = collect();

        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $translations = $this->translations(
                translatable: $translatable,
                attribute: $attribute,
            );

            $localeCodes->push(...array_keys($translations));
        }

        return $localeCodes
            ->unique()
            ->map(
                fn (string $localeCode): Translation => new Translation(
                    translatable: $translatable,
                    localeCode: $localeCode,
                    attributes: $this->attributes(
                        translatable: $translatable,
                        localeCode: $localeCode,
                    ),
                ),
            )
            ->values();
    }

    public function exists(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
    ): bool {
        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $translations = $this->translations(
                translatable: $translatable,
                attribute: $attribute,
            );

            if (array_key_exists($localeCode, $translations)) {
                return true;
            }
        }

        return false;
    }

    public function create(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        $shouldPersist = $translatable->exists;

        foreach ($attributes as $attribute => $value) {
            $translations = $this->translations(
                translatable: $translatable,
                attribute: $attribute,
            );

            $translations[$localeCode] = $value;

            $translatable->setAttribute(
                $attribute,
                $translations,
            );
        }

        if ($shouldPersist) {
            $this->persist($translatable);
        }

        return new Translation(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );
    }

    public function update(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        foreach ($attributes as $attribute => $value) {
            $translations = $this->translations(
                translatable: $translatable,
                attribute: $attribute,
            );

            $translations[$localeCode] = $value;

            $translatable->setAttribute(
                $attribute,
                $translations,
            );
        }

        $this->persist($translatable);

        return $this->get(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    public function delete(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
    ): void {
        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $translations = $this->translations(
                translatable: $translatable,
                attribute: $attribute,
            );

            unset($translations[$localeCode]);

            $translatable->setAttribute(
                $attribute,
                $translations,
            );
        }

        $this->persist($translatable);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
    ): array {
        $attributes = [];

        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $translations = $this->translations(
                translatable: $translatable,
                attribute: $attribute,
            );

            if (array_key_exists($localeCode, $translations)) {
                $attributes[$attribute] = $translations[$localeCode];
            }
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function translations(
        Model&JsonTranslatableModel $translatable,
        string $attribute,
    ): array {
        return $translatable->getAttributeValue($attribute) ?? [];
    }

    private function persist(
        Model&JsonTranslatableModel $translatable,
    ): void {
        $attributes = [];

        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            if ($translatable->isDirty($attribute)) {
                $attributes[$attribute] = $translatable->getAttributeValue($attribute);
            }
        }

        if ($attributes === []) {
            return;
        }

        $model = $translatable->newQuery()
            ->findOrFail($translatable->getKey());

        foreach ($attributes as $attribute => $value) {
            $model->setAttribute($attribute, $value);
        }

        $model->save();

        $translatable->syncOriginalAttributes(
            array_keys($attributes),
        );
    }
}
