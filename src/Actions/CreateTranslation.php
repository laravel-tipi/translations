<?php

declare(strict_types=1);

namespace Tipi\Translations\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;
use Throwable;
use Tipi\Translations\Contracts\LocaleProvider;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Exceptions\TranslationAlreadyExistsException;
use Tipi\Translations\Translation;
use Tipi\Translations\TranslationManager;

final readonly class CreateTranslation
{
    public function __construct(
        private LocaleProvider $locales,
        private TranslationManager $translations,
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
        $translatable = $this->lockTranslatable->execute(
            translatable: $translatable,
        );

        $localeCode ??= $this->locales->default()->code;

        if ($this->translations->exists(
            translatable: $translatable,
            localeCode: $localeCode,
        )) {
            throw new TranslationAlreadyExistsException(
                code: $localeCode,
            );
        }

        return $this->translations->create(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );
    }
}
