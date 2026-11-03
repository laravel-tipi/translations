<?php

declare(strict_types=1);

namespace Tipi\Translations\Models;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;

/**
 * Editorial state of one locale of one translatable model: an optional
 * status and since when the translation is outdated.
 *
 * @property string $locale_code
 * @property BackedEnum|string|null $status
 * @property CarbonImmutable|null $outdated_at
 */
class TranslationState extends Model
{
    protected $guarded = [];

    /**
     * Drafts are unpublished content: never part of serialized output.
     *
     * @var list<string>
     */
    protected $hidden = ['drafts', 'sources'];

    public function getTable(): string
    {
        return (string) config('translations.states.table', 'translation_states');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isOutdated(): bool
    {
        return $this->outdated_at !== null;
    }

    /**
     * The configured status enum, or null when statuses are not used.
     *
     * @return class-string<BackedEnum>|null
     */
    public static function statusEnum(): ?string
    {
        $enum = config('translations.states.status_enum');

        if ($enum === null) {
            return null;
        }

        if (! is_string($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw InvalidTranslatableModelException::invalidStatus('[translations.states.status_enum] must be a backed enum class');
        }

        return $enum;
    }

    protected function casts(): array
    {
        $enum = static::statusEnum();

        return [
            'outdated_at' => 'immutable_datetime',
            'drafts' => 'array',
            'sources' => 'array',
            ...($enum === null ? [] : ['status' => $enum]),
        ];
    }
}
