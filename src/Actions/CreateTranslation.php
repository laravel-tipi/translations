<?php

declare(strict_types=1);

namespace Tipi\Localization\Translations\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;
use Tipi\Localization\Exceptions\TranslationAlreadyExistsException;
use Tipi\Localization\LocaleResolver;
use Tipi\Localization\Translations\Contracts\TranslatableModel;
use Tipi\Localization\Translations\Contracts\TranslationModelContract;

final readonly class CreateTranslation
{
    public function __construct(
        private LocaleResolver $localeResolver,
    ) {}

    /**
     * @throws Throwable
     */
    public function execute(TranslatableModel $translatable, array $attributes, ?string $localeCode = null, bool $dbTransaction = true): TranslationModelContract
    {
        if (! $parent instanceof Model) {
            throw new LogicException(
                'The parent must extend Eloquent Model.',
            );
        }

        if ($dbTransaction) {
            /** @var Model&TranslatableModel $parent */
            return DB::transaction(
                fn (): TranslationModelContract => $this->create(
                    parent: $parent,
                    attributes: $attributes,
                    localeCode: $localeCode,
                ),
            );
        }

        /** @var Model&TranslatableModel $parent */
        return $this->create(
            parent: $parent,
            attributes: $attributes,
            localeCode: $localeCode,
        );
    }

    /**
     * TODO: add a boolean allowing user to decide if the $parent should be locked, or even queried.
     * TODO: consider changing variable name from $parent to $translatable.
     * TODO: also allow $parent to be integer or string as well (so it works for every primary key).
     */
    /// TODO:
    private function create(Model&TranslatableModel $parent, array $attributes, ?string $localeCode): TranslationModelContract
    {
        $parent = $parent->newQuery()
            ->lockForUpdate()
            ->findOrFail($parent->getKey());

        $locale = $localeCode === null
            ? $this->localeResolver->getDefaultLocale()
            : $this->localeResolver->getSupportedLocale($localeCode);

        if ($parent->translationExistsForLocale($locale->getKey())) {
            throw new TranslationAlreadyExistsException(
                code: $locale->code,
            );
        }

        $translationModelClass = $parent::getTranslationModelClass();

        /** @var Model&TranslationModelContract $translation */
        $translation = new $translationModelClass;

        $translation->forceFill([
            ...$attributes,
            'locale_code' => $locale->getKey(),
        ]);

        $translation
            ->translationParent()
            ->associate($parent);

        $translation->save();

        return $translation;
    }
}
