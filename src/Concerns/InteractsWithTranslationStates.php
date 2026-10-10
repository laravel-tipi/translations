<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Tipi\Translations\Config\TranslationConfig;
use Tipi\Translations\TranslationStateManager;

trait InteractsWithTranslationStates
{
    /**
     * @var array<string, true>
     */
    protected array $pendingTranslationStates = [];

    protected static function bootInteractsWithTranslationStates(): void
    {
        static::created(function (Model $model): void {
            /** @var self $model */
            if ($model->pendingTranslationStates === []) {
                return;
            }

            $manager = app(TranslationStateManager::class);

            foreach (array_keys($model->pendingTranslationStates) as $localeCode) {
                $manager->create(
                    translatable: $model,
                    localeCode: $localeCode,
                );
            }

            $model->pendingTranslationStates = [];
        });

        static::deleted(function (Model $model): void {
            if (
                method_exists($model, 'isForceDeleting')
                && ! $model->isForceDeleting()
            ) {
                return;
            }

            $model->translationStates()->delete();
            $model->unsetRelation('translationStates');
        });
    }

    public function translationStates(): MorphMany
    {
        return $this->morphMany(
            related: resolve(TranslationConfig::class)->translationStateModel,
            name: 'translatable',
        );
    }

    public function queueTranslationState(string $localeCode): void
    {
        $this->pendingTranslationStates[] = $localeCode;
    }
}
