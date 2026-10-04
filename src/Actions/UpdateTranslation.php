<?php

declare(strict_types=1);

namespace Tipi\Localization\Translations\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;
use Tipi\Localization\Translations\Contracts\TranslationModelContract;

final readonly class UpdateTranslation
{
    /**
     * @throws Throwable
     */
    public function execute(TranslationModelContract $translation, array $attributes, bool $markOthersAsOutdated = false, bool $dbTransaction = true): TranslationModelContract
    {
        if (! $translation instanceof Model) {
            throw new LogicException(
                'The translation must extend Eloquent Model.',
            );
        }

        if ($dbTransaction) {
            /** @var Model&TranslationModelContract $translation */
            return DB::transaction(
                fn (): TranslationModelContract => $this->update(
                    translation: $translation,
                    attributes: $attributes,
                    markOthersAsOutdated: $markOthersAsOutdated
                ),
            );
        }

        /** @var Model&TranslationModelContract $translation */
        return $this->update(
            translation: $translation,
            attributes: $attributes,
            markOthersAsOutdated: $markOthersAsOutdated
        );
    }

    private function update(Model&TranslationModelContract $translation, array $attributes, bool $markOthersAsOutdated): TranslationModelContract
    {
        $translation = $translation->newQuery()
            ->lockForUpdate()
            ->findOrFail($translation->getKey());

        if (! $translation->canBeUpdated()) {
            throw new LogicException('This translation cannot be updated.');
        }

        if ($markOthersAsOutdated && ! $translation->isDefault()) {
            throw new LogicException(
                'Only the default translation can mark other translations as outdated.',
            );
        }

        /** @var Model&TranslationModelContract $translation */
        $translation->forceFill($attributes);
        $translation->outdated_at = null;
        $translation->save();

        if ($markOthersAsOutdated) {
            $parent = $translation->translationParent()
                ->firstOrFail();

            $parent->translations()
                ->whereKeyNot($translation->getKey())
                ->update([
                    'outdated_at' => now(),
                ]);
        }

        return $translation;
    }
}
