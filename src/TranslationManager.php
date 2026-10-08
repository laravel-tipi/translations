<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\EmptyTranslationException;
use Tipi\Translations\Exceptions\InvalidTranslationAttributeException;
use Tipi\Translations\Exceptions\InvalidTranslationConfigurationException;
use Tipi\Translations\Stores\DedicatedTableTranslationStore;
use Tipi\Translations\Stores\JsonTranslationStore;
use Tipi\Translations\Stores\SharedTableTranslationStore;

final readonly class TranslationManager
{
    public function __construct(
        private JsonTranslationStore $jsonStore,
        private DedicatedTableTranslationStore $dedicatedTableStore,
        private SharedTableTranslationStore $sharedTableStore,
    ) {}

    /**
     * @return Collection<int, Translation>
     */
    public function getAll(
        Model&TranslatableModel $translatable,
    ): Collection {
        return $this->store($translatable)->all(
            translatable: $translatable,
        );
    }

    public function get(
        Model&TranslatableModel $translatable,
        string $localeCode,
    ): ?Translation {
        return $this->store($translatable)->get(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    public function exists(
        Model&TranslatableModel $translatable,
        string $localeCode,
    ): bool {
        return $this->store($translatable)->exists(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    public function create(
        Model&TranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        $store = $this->store($translatable);

        $this->ensureCanCreateTranslation($translatable);

        $this->validateAttributes(
            translatable: $translatable,
            attributes: $attributes,
        );

        $translation = $store->create(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );

        $translatable->setResolvedTranslation(
            localeCode: $localeCode,
            translation: $translation,
        );

        return $translation;
    }

    /**
     * @throws Throwable
     */
    public function update(
        Model&TranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        $store = $this->store($translatable);

        $this->validateAttributes(
            translatable: $translatable,
            attributes: $attributes,
        );

        $translation = $store->update(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );

        $translatable->setResolvedTranslation(
            localeCode: $localeCode,
            translation: $translation,
        );

        return $translation;
    }

    /**
     * @throws Throwable
     */
    public function delete(
        Model&TranslatableModel $translatable,
        string $localeCode,
    ): void {
        $this->store($translatable)->delete(
            translatable: $translatable,
            localeCode: $localeCode,
        );

        $translatable->setResolvedTranslation(
            localeCode: $localeCode,
            translation: null,
        );
    }

    private function store(
        Model&TranslatableModel $translatable,
    ): DedicatedTableTranslationStore|JsonTranslationStore|SharedTableTranslationStore {
        $strategies = array_filter([
            'json' => $translatable instanceof JsonTranslatableModel,
            'dedicated' => $translatable instanceof DedicatedTableTranslatableModel,
            'shared' => $translatable instanceof SharedTableTranslatableModel,
        ]);

        if ($strategies === []) {
            throw InvalidTranslationConfigurationException::missingStorageStrategy(
                model: $translatable,
            );
        }

        if (count($strategies) > 1) {
            throw InvalidTranslationConfigurationException::multipleStorageStrategies(
                model: $translatable,
            );
        }

        return match (array_key_first($strategies)) {
            'json' => $this->jsonStore,
            'dedicated' => $this->dedicatedTableStore,
            'shared' => $this->sharedTableStore,
        };
    }

    private function validateAttributes(
        Model&TranslatableModel $translatable,
        array $attributes,
    ): void {
        if ($attributes === []) {
            throw EmptyTranslationException::create();
        }

        $invalidAttributes = array_diff(
            array_keys($attributes),
            $translatable::getTranslatableAttributes(),
        );

        if ($invalidAttributes !== []) {
            throw InvalidTranslationAttributeException::forAttributes(
                model: $translatable::class,
                attributes: array_values($invalidAttributes),
            );
        }
    }

    private function ensureCanCreateTranslation(
        Model&TranslatableModel $translatable,
    ): void {
        if (
            ! $translatable->exists
            && (
                $translatable instanceof DedicatedTableTranslatableModel
                || $translatable instanceof SharedTableTranslatableModel
            )
        ) {
            throw new LogicException(
                'Translations using table storage require the translatable model to be persisted.',
            );
        }
    }
}
