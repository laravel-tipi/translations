<?php

declare(strict_types=1);

namespace Tipi\Translations;

use Illuminate\Database\Eloquent\Model;
use JsonException;
use LogicException;
use Throwable;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;
use Tipi\Translations\Contracts\JsonTranslatableModel;
use Tipi\Translations\Contracts\SharedTableTranslatableModel;
use Tipi\Translations\Contracts\TracksOutdatedTranslations;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\InvalidTranslationAttributeException;
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

    /**
     * @throws JsonException
     */
    public function create(
        Model&TranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        $this->validateAttributes(
            translatable: $translatable,
            attributes: $attributes,
        );

        return $this->store($translatable)->create(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );
    }

    /**
     * @throws JsonException|Throwable
     */
    public function update(
        Model&TranslatableModel $translatable,
        string $localeCode,
        array $attributes,
    ): Translation {
        $this->validateAttributes(
            translatable: $translatable,
            attributes: $attributes,
        );

        return $this->store($translatable)->update(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );
    }

    /**
     * @throws JsonException|Throwable
     */
    public function delete(
        Model&TranslatableModel $translatable,
        string $localeCode,
    ): void {
        $this->store($translatable)->delete(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    public function markOthersAsOutdated(
        Model&TranslatableModel&TracksOutdatedTranslations $translatable,
        string $localeCode,
    ): void {
        $this->outdatedTrackingStore($translatable)->markOthersAsOutdated(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    private function store(
        Model&TranslatableModel $translatable,
    ): DedicatedTableTranslationStore|JsonTranslationStore|SharedTableTranslationStore {
        return match (true) {
            $translatable instanceof JsonTranslatableModel => $this->jsonStore,
            $translatable instanceof DedicatedTableTranslatableModel => $this->dedicatedTableStore,
            $translatable instanceof SharedTableTranslatableModel => $this->sharedTableStore,
            default => throw new LogicException(sprintf(
                'Translatable model [%s] does not define a supported translation storage strategy.',
                $translatable::class,
            )),
        };
    }

    private function outdatedTrackingStore(
        Model&TranslatableModel&TracksOutdatedTranslations $translatable,
    ): DedicatedTableTranslationStore|SharedTableTranslationStore {
        return match (true) {
            $translatable instanceof DedicatedTableTranslatableModel => $this->dedicatedTableStore,
            $translatable instanceof SharedTableTranslatableModel => $this->sharedTableStore,
            default => throw new LogicException(sprintf(
                'Translatable model [%s] does not support outdated translation tracking.',
                $translatable::class,
            )),
        };
    }

    private function validateAttributes(
        Model&TranslatableModel $translatable,
        array $attributes,
    ): void {
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
}
