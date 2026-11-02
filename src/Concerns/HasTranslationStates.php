<?php

declare(strict_types=1);

namespace Tipi\Translations\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Tipi\Support\Locale;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;
use Tipi\Translations\Models\TranslationState;

/**
 * Editorial state per translated locale: whether it is outdated and an
 * optional status (an application enum). Works with both storages.
 *
 * Kept in sync on save(): a locale whose translations changed is marked
 * current, and a locale that lost all its values loses its state.
 *
 * @mixin Model
 */
trait HasTranslationStates
{
    public static function bootHasTranslationStates(): void
    {
        static::deleted(function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            /** @var Model&self $model */
            $model->translationStates()->delete();
            $model->unsetRelation('translationStates');
        });
    }

    /**
     * @return MorphMany<TranslationState, $this>
     */
    public function translationStates(): MorphMany
    {
        return $this->morphMany(config('translations.states.model', TranslationState::class), 'translatable');
    }

    public function getTranslationState(string|Locale $locale): ?TranslationState
    {
        $code = $this->translationLocales()->code($locale);

        return $this->getRelationValue('translationStates')->firstWhere('locale_code', $code);
    }

    public function isTranslationOutdated(string|Locale $locale): bool
    {
        return $this->getTranslationState($locale)?->isOutdated() === true;
    }

    /**
     * Mark the locale outdated. An already outdated locale keeps the time
     * it became outdated.
     */
    public function markTranslationOutdated(string|Locale $locale): TranslationState
    {
        $state = $this->translationStateFor($this->translationLocales()->code($locale));

        if ($state->outdated_at === null) {
            $state->outdated_at = now();
            $state = $this->saveTranslationState($state);
        }

        return $state;
    }

    public function markTranslationCurrent(string|Locale $locale): TranslationState
    {
        $state = $this->translationStateFor($this->translationLocales()->code($locale));

        if ($state->outdated_at !== null || ! $state->exists) {
            $state->outdated_at = null;
            $state = $this->saveTranslationState($state);
        }

        return $state;
    }

    /**
     * After changing the source text: mark every other translated locale
     * outdated.
     */
    public function markOtherTranslationsOutdated(string|Locale $source): void
    {
        $source = $this->translationLocales()->code($source);

        foreach ($this->getTranslatedLocales() as $locale) {
            if ($locale !== $source) {
                $this->markTranslationOutdated($locale);
            }
        }
    }

    public function setTranslationStatus(string|Locale $locale, ?BackedEnum $status): TranslationState
    {
        $enum = TranslationState::statusEnum();

        if ($status !== null && $enum === null) {
            throw InvalidTranslatableModelException::invalidStatus('statuses are not configured ([translations.states.status_enum])');
        }

        if ($status !== null && ! $status instanceof $enum) {
            throw InvalidTranslatableModelException::invalidStatus(sprintf('expected an instance of [%s], got [%s]', $enum, $status::class));
        }

        $state = $this->translationStateFor($this->translationLocales()->code($locale));
        $state->status = $status;

        return $this->saveTranslationState($state);
    }

    /**
     * Called by save() for the locales whose translations changed.
     *
     * @param  list<string>  $locales
     */
    protected function syncTranslationStates(array $locales): void
    {
        $translated = $this->getTranslatedLocales();

        foreach ($locales as $locale) {
            if (in_array($locale, $translated, true)) {
                $this->markTranslationCurrent($locale);

                continue;
            }

            $state = $this->loadedTranslationStates()->firstWhere('locale_code', $locale);

            if ($state !== null) {
                $state->delete();
                $this->setRelation(
                    'translationStates',
                    $this->getRelation('translationStates')->reject(fn (TranslationState $candidate): bool => $candidate === $state)->values(),
                );
            }
        }
    }

    /**
     * Save a state. A new state that another process created meanwhile is
     * updated instead.
     */
    private function saveTranslationState(TranslationState $state): TranslationState
    {
        if ($state->exists) {
            $state->save();

            return $state;
        }

        try {
            // A savepoint, so a failed insert leaves a surrounding transaction usable.
            $state->getConnection()->transaction(fn (): bool => $state->save());

            return $state;
        } catch (UniqueConstraintViolationException) {
            $existing = $this->translationStates()->where('locale_code', $state->locale_code)->firstOrFail();
            $existing->forceFill(Arr::only($state->getAttributes(), ['status', 'outdated_at']))->save();
            $this->unsetRelation('translationStates');

            return $existing;
        }
    }

    /**
     * The states, loaded explicitly for writes: saving must not trip
     * Model::preventLazyLoading().
     *
     * @return Collection<int, TranslationState>
     */
    private function loadedTranslationStates(): Collection
    {
        if (! $this->relationLoaded('translationStates')) {
            $this->setRelation('translationStates', $this->translationStates()->get());
        }

        return $this->getRelation('translationStates');
    }

    /**
     * The existing state, or a new unsaved one added to the loaded
     * relation.
     */
    private function translationStateFor(string $locale): TranslationState
    {
        $states = $this->loadedTranslationStates();

        $state = $states->firstWhere('locale_code', $locale);

        if ($state === null) {
            /** @var TranslationState $state */
            $state = $this->translationStates()->make(['locale_code' => $locale]);
            $states->push($state);
        }

        return $state;
    }
}
