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
use Tipi\Translations\Exceptions\AttributeNotTranslatableException;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;
use Tipi\Translations\Models\TranslationState;

/**
 * Editorial state per translated locale: whether it is outdated, an
 * optional status (an application enum), unpublished drafts and what the
 * translations were made from (sources). Works with every storage.
 *
 * Kept in sync on save(): a locale whose translations changed is marked
 * current, a changed translation saved without a source loses its old
 * one, and a locale that lost all its values loses its state unless it
 * has drafts.
 *
 * @mixin Model
 */
trait HasTranslationStates
{
    /**
     * Deleted with the model (not on soft delete), in one transaction.
     *
     * @return MorphMany<TranslationState, $this>
     */
    /**
     * Draft and source changes not written yet: locale => kind
     * ("drafts", "sources") => attribute => value (null removes). A draft
     * is stored as ['value' => …, 'source' => …].
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    protected array $translationStateChanges = [];

    public function translationStates(): MorphMany
    {
        return $this->translationMorphMany(config('translations.states.model', TranslationState::class));
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

    /*
     | Drafts
     |
     | A draft is an unpublished value of an attribute in a locale: work in
     | progress kept beside the published value, with its own source (what
     | it was made from, see Sources). Reading translations ($model->name,
     | getTranslation(), fallback, queries, serialization) never sees
     | drafts, so an unfinished translation is never shown. Publishing a
     | draft is setting the translation (and its source) and forgetting the
     | draft, which the application does explicitly.
     */

    public function getTranslationDraft(string $attribute, string|Locale $locale): mixed
    {
        return $this->draftEntry($attribute, $locale)['value'] ?? null;
    }

    /**
     * What the draft was made from: the source given with it.
     */
    public function getTranslationDraftSource(string $attribute, string|Locale $locale): mixed
    {
        return $this->draftEntry($attribute, $locale)['source'] ?? null;
    }

    public function hasTranslationDraft(string $attribute, string|Locale $locale): bool
    {
        return $this->getTranslationDraft($attribute, $locale) !== null;
    }

    /**
     * @return array<string, mixed> attribute => draft
     */
    public function getTranslationDrafts(string|Locale $locale): array
    {
        return array_filter(
            array_map(fn (mixed $entry): mixed => is_array($entry) ? ($entry['value'] ?? null) : null, $this->stateValues('drafts', $locale)),
            fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * Set a draft and what it was made from, written by the next save() in
     * its transaction. A null value removes the draft and its source.
     */
    public function setTranslationDraft(string $attribute, string|Locale $locale, mixed $value, mixed $source = null): static
    {
        return $this->changeStateValue('drafts', $attribute, $locale, $value === null ? null : ['value' => $value, 'source' => $source]);
    }

    public function forgetTranslationDraft(string $attribute, string|Locale $locale): static
    {
        return $this->setTranslationDraft($attribute, $locale, null);
    }

    /**
     * @return array{value?: mixed, source?: mixed}
     */
    private function draftEntry(string $attribute, string|Locale $locale): array
    {
        $entry = $this->stateValue('drafts', $attribute, $locale);

        return is_array($entry) ? $entry : [];
    }

    /*
     | Sources
     |
     | What the published translation was made from: any JSON value per
     | attribute and locale (a fingerprint of the source text, per segment,
     | a revision id), set by whoever translates and compared by them to
     | tell when the source changed since. Written like drafts.
     |
     | A source describes one value: when a save changes a translation
     | without setting its source, the source is forgotten, so it never
     | vouches for a value it wasn't recorded for.
     */

    public function getTranslationSource(string $attribute, string|Locale $locale): mixed
    {
        return $this->stateValue('sources', $attribute, $locale);
    }

    public function setTranslationSource(string $attribute, string|Locale $locale, mixed $value): static
    {
        return $this->changeStateValue('sources', $attribute, $locale, $value);
    }

    /**
     * Unsaved draft and source changes: locale => kind => attribute => value.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function getDirtyTranslationStates(): array
    {
        return $this->translationStateChanges;
    }

    private function stateValue(string $kind, string $attribute, string|Locale $locale): mixed
    {
        $this->ensureTranslatableState($attribute);
        $code = $this->translationLocales()->code($locale);

        if (array_key_exists($attribute, $this->translationStateChanges[$code][$kind] ?? [])) {
            return $this->translationStateChanges[$code][$kind][$attribute];
        }

        return $this->getTranslationState($code)?->getAttribute($kind)[$attribute] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stateValues(string $kind, string|Locale $locale): array
    {
        $code = $this->translationLocales()->code($locale);
        $values = array_replace($this->getTranslationState($code)?->getAttribute($kind) ?? [], $this->translationStateChanges[$code][$kind] ?? []);

        $values = array_filter($values, fn (mixed $value): bool => $value !== null);

        // In declaration order: databases may reorder JSON keys.
        return array_intersect_key(array_replace(array_flip($this->getTranslatableAttributes()), $values), $values);
    }

    private function changeStateValue(string $kind, string $attribute, string|Locale $locale, mixed $value): static
    {
        $this->ensureTranslatableState($attribute);

        $this->translationStateChanges[$this->translationLocales()->code($locale)][$kind][$attribute] = $value;

        return $this;
    }

    private function ensureTranslatableState(string $attribute): void
    {
        if (! $this->isTranslatableAttribute($attribute)) {
            throw new AttributeNotTranslatableException(static::class, $attribute);
        }
    }

    /**
     * Inside save()'s transaction, after the model row and translations:
     * merge the pending draft and source changes into the locked state
     * rows. Other attributes' values written meanwhile are kept.
     */
    protected function writeTranslationStateChanges(): void
    {
        if ($this->translationStateChanges === []) {
            return;
        }

        $this->lockTranslationOwnerOrFail();

        foreach ($this->translationLocales()->sort($this->translationStateChanges) as $locale => $kinds) {
            $state = $this->translationStates()->where('locale_code', (string) $locale)->lockForUpdate()->first()
                ?? $this->translationStates()->make(['locale_code' => (string) $locale]);

            foreach ($kinds as $kind => $values) {
                $merged = array_filter(
                    array_replace($state->getAttribute($kind) ?? [], $values),
                    fn (mixed $value): bool => $value !== null,
                );

                $state->setAttribute($kind, $merged === [] ? null : $merged);
            }

            if ($state->exists || $state->getAttribute('drafts') !== null || $state->getAttribute('sources') !== null) {
                $this->ensureTranslationConnection($state);
                $state->save();
            }
        }

        $this->translationStateChanges = [];
        $this->unsetRelation('translationStates');
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function pendingTranslationStateChanges(): array
    {
        return $this->translationStateChanges;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $changes
     */
    protected function restoreTranslationStateChanges(array $changes): void
    {
        $this->translationStateChanges = $changes;
    }

    /**
     * Called by save() with the translations it wrote: locale => attribute
     * => value.
     *
     * @param  array<string, array<string, mixed>>  $changes
     */
    protected function syncTranslationStates(array $changes): void
    {
        $translated = $this->getTranslatedLocales();

        foreach ($changes as $locale => $attributes) {
            $locale = (string) $locale;

            // A changed translation without a new source has none: written
            // with the state changes, later in the same save.
            $sources = $this->loadedTranslationStates()->firstWhere('locale_code', $locale)?->sources ?? [];

            foreach (array_keys($attributes) as $attribute) {
                if (isset($sources[$attribute]) && ! array_key_exists($attribute, $this->translationStateChanges[$locale]['sources'] ?? [])) {
                    $this->translationStateChanges[$locale]['sources'][$attribute] = null;
                }
            }

            if (in_array($locale, $translated, true)) {
                $this->markTranslationCurrent($locale);

                continue;
            }

            $state = $this->loadedTranslationStates()->firstWhere('locale_code', $locale);

            // A locale with drafts keeps them, without the outdated flag.
            if ($state !== null && ($state->drafts ?? []) !== []) {
                if ($state->outdated_at !== null) {
                    $state->outdated_at = null;
                    $state->save();
                }

                continue;
            }

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
        $this->ensureTranslationConnection($state);

        if ($state->exists) {
            $state->save();

            return $state;
        }

        try {
            // A transaction (a savepoint inside one), so a failed insert
            // leaves a surrounding transaction usable, and the model's row
            // stays locked until the state is in.
            $state->getConnection()->transaction(function () use ($state): void {
                $this->lockTranslationOwnerOrFail();
                $state->save();
            });

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
