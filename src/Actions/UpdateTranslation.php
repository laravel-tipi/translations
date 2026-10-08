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
use Tipi\Translations\Exceptions\TranslationDoesNotExistException;
use Tipi\Translations\Translation;
use Tipi\Translations\TranslationManager;
use Tipi\Translations\TranslationStateManager;

final readonly class UpdateTranslation
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
        bool $markOthersAsOutdated = false,
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
                fn (): Translation => $this->update(
                    translatable: $translatable,
                    attributes: $attributes,
                    localeCode: $localeCode,
                    markOthersAsOutdated: $markOthersAsOutdated,
                ),
            );
        }

        /** @var Model&TranslatableModel $translatable */
        return $this->update(
            translatable: $translatable,
            attributes: $attributes,
            localeCode: $localeCode,
            markOthersAsOutdated: $markOthersAsOutdated,
        );
    }

    /**
     * @throws JsonException|Throwable
     */
    private function update(
        Model&TranslatableModel $translatable,
        array $attributes,
        ?string $localeCode,
        bool $markOthersAsOutdated,
    ): Translation {
        $translatable = $this->lockTranslatable->execute(
            translatable: $translatable,
        );

        $localeCode ??= $this->locales->current()->code;

        if (! $this->translations->exists($translatable, $localeCode)) {
            throw new TranslationDoesNotExistException(
                code: $localeCode,
            );
        }

        if ($markOthersAsOutdated) {
            if (! $translatable instanceof HasTranslationStates) {
                throw new LogicException(sprintf(
                    'Translatable model [%s] does not support translation states.',
                    $translatable::class,
                ));
            }

            if ($localeCode !== $this->locales->default()->code) {
                throw new LogicException(
                    'Only the default translation can mark other translations as outdated.',
                );
            }
        }

        $translation = $this->translations->update(
            translatable: $translatable,
            localeCode: $localeCode,
            attributes: $attributes,
        );

        if ($translatable instanceof HasTranslationStates) {
            $this->translationStates->markAsCurrent(
                translatable: $translatable,
                localeCode: $localeCode,
            );

            if ($markOthersAsOutdated) {
                $this->translationStates->markOthersAsOutdated(
                    translatable: $translatable,
                    localeCode: $localeCode,
                );
            }
        }

        return $translation;
    }
}
