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
use Tipi\Translations\Exceptions\DefaultTranslationCannotBeDeletedException;
use Tipi\Translations\Exceptions\TranslationDoesNotExistException;
use Tipi\Translations\TranslationManager;

final readonly class DeleteTranslation
{
    public function __construct(
        private LocaleProvider $locales,
        private TranslationManager $translations,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(
        TranslatableModel $translatable,
        ?string $localeCode = null,
        bool $dbTransaction = true,
    ): void {
        if (! $translatable instanceof Model) {
            throw new LogicException(
                'The translatable must extend Eloquent Model.',
            );
        }

        if ($dbTransaction) {
            /** @var Model&TranslatableModel $translatable */
            DB::transaction(
                fn () => $this->delete(
                    translatable: $translatable,
                    localeCode: $localeCode,
                ),
            );

            return;
        }

        /** @var Model&TranslatableModel $translatable */
        $this->delete(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }

    /**
     * @throws JsonException|Throwable
     */
    private function delete(
        Model&TranslatableModel $translatable,
        ?string $localeCode,
    ): void {
        $translatable = $translatable->newQuery()
            ->lockForUpdate()
            ->findOrFail($translatable->getKey());

        $localeCode ??= $this->locales->current()->code;

        if ($localeCode === $this->locales->default()->code) {
            throw new DefaultTranslationCannotBeDeletedException(
                code: $localeCode,
            );
        }

        if (! $this->translations->exists(
            translatable: $translatable,
            localeCode: $localeCode,
        )) {
            throw new TranslationDoesNotExistException(
                code: $localeCode,
            );
        }

        $this->translations->delete(
            translatable: $translatable,
            localeCode: $localeCode,
        );
    }
}
