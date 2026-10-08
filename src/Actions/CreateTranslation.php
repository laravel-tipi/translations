<?php

declare(strict_types=1);

namespace Tipi\Translations\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Throwable;
use Tipi\Translations\Contracts\HasTranslationStates;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\TranslationAlreadyExistsException;
use Tipi\Translations\Translation;
use Tipi\Translations\TranslationManager;
use Tipi\Translations\TranslationStateManager;

final readonly class CreateTranslation
{
    public function __construct(
        private LocaleProvider $locales,
        private TranslationManager $translations,
        private TranslationStateManager $translationStates,
        private LockTranslatable $lockTranslatable,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(
        TranslatableModel $translatable,
        array $attributes,
        ?string $localeCode = null,
        bool $dbTransaction = true,
    ): Translation {
        if (! $translatable instanceof Model) {
            throw new LogicException(
                'The translatable must extend Eloquent Model.',
            );
        }

        if ($dbTransaction) {
            /** @var Model&TranslatableModel $translatable */
            return DB::transaction(
                fn (): Translation => $this->create(
                    translatable: $translatable,
                    attributes: $attributes,
                    localeCode: $localeCode,
                ),
            );
        }

        /** @var Model&TranslatableModel $translatable */
        return $this->create(
            translatable: $translatable,
            attributes: $attributes,
            localeCode: $localeCode,
        );
    }

    /**
     * @throws JsonException
     */
    private function create(
        Model&TranslatableModel $translatable,
        array $attributes,
        ?string $localeCode,
    ): Translation {
        if ($translatable->exists) {
            $translatable = $this->lockTranslatable->execute(
                translatable: $translatable,
            );
        }

        $localeCode ??= $this->locales->current()->code;

        if ($this->translations->exists(
            translatable: $translatable,
            localeCode: $localeCode,
        )) {
            throw new TranslationAlreadyExistsException(
                code: $localeCode,
            );
        }

        $translation = $this->translations->create(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );

        if ($translatable instanceof HasTranslationStates) {
            $this->translationStates->create(
                /** @var Model&TranslatableModel&HasTranslationStates $translatable */
                translatable: $translatable,
                localeCode: $localeCode,
            );
        }

        return $translation;
    }
}
