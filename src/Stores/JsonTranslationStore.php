<?php

declare(strict_types=1);

namespace Tipi\Translations\Stores;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Translation;

final readonly class JsonTranslationStore
{
    /**
     * @throws JsonException
     */
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
     *
     * @throws JsonException
     */
    public function all(
        Model&JsonTranslatableModel $translatable,
    ): Collection {
        $localeCodes = collect();

        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $translations = $this->decode(
                $this->rawAttribute(
                    translatable: $translatable,
                    attribute: $attribute,
                )
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

    /**
     * @throws JsonException
     */
    public function exists(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
    ): bool {
        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $translations = $this->decode(
                $this->rawAttribute(
                    translatable: $translatable,
                    attribute: $attribute,
                ),
            );

            if (array_key_exists($localeCode, $translations)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws JsonException
     */
    public function create(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        foreach ($attributes as $attribute => $value) {
            $translations = $this->decode(
                $this->rawAttribute(
                    translatable: $translatable,
                    attribute: $attribute,
                ),
            );

            $translations[$localeCode] = $value;

            $translatable->setAttribute(
                $attribute,
                $this->encode($translations),
            );
        }

        $translatable->save();

        return new Translation(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );
    }

    /**
     * @throws JsonException
     */
    public function update(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        foreach ($attributes as $attribute => $value) {
            $translations = $this->decode(
                $this->rawAttribute(
                    translatable: $translatable,
                    attribute: $attribute,
                ),
            );

            $translations[$localeCode] = $value;

            $translatable->setAttribute(
                $attribute,
                $this->encode($translations),
            );
        }

        $translatable->save();

        return $this->get(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    /**
     * @throws JsonException
     */
    public function delete(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
    ): void {
        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $translations = $this->decode(
                $this->rawAttribute(
                    translatable: $translatable,
                    attribute: $attribute,
                ),
            );

            unset($translations[$localeCode]);

            $translatable->setAttribute(
                $attribute,
                $this->encode($translations),
            );
        }

        $translatable->save();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function attributes(
        Model&JsonTranslatableModel $translatable,
        string $localeCode,
    ): array {
        $attributes = [];

        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            $translations = $this->decode(
                $this->rawAttribute(
                    translatable: $translatable,
                    attribute: $attribute,
                ),
            );

            if (array_key_exists($localeCode, $translations)) {
                $attributes[$attribute] = $translations[$localeCode];
            }
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decode(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        return json_decode(
            $value,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @throws JsonException
     */
    private function encode(array $translations): string
    {
        return json_encode(
            $translations,
            JSON_THROW_ON_ERROR,
        );
    }

    private function rawAttribute(
        Model&JsonTranslatableModel $translatable,
        string $attribute,
    ): mixed {
        return $translatable->getAttributes()[$attribute] ?? null;
    }
}
