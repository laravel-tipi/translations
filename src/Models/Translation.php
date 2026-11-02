<?php

declare(strict_types=1);

namespace Tipi\Translations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Tipi\Translations\Exceptions\InvalidTranslatableModelException;

/**
 * One translated value of shared table storage: one attribute of one
 * model in one locale. The value is JSON, so it keeps its type.
 *
 * Rows are written by the translatable models; use this model to read
 * them across models (reports, exports), not to change them.
 *
 * @property string $translatable_type
 * @property int|string $translatable_id
 * @property string $locale_code
 * @property string $attribute
 * @property mixed $value
 */
class Translation extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('translations.shared.table', 'translations');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The configured translation model.
     *
     * @return class-string<Translation>
     */
    public static function modelClass(): string
    {
        $model = config('translations.shared.model', self::class);

        if (! is_string($model) || ! is_a($model, self::class, true)) {
            throw InvalidTranslatableModelException::invalidConfiguration('[translations.shared.model] must extend '.self::class);
        }

        return $model;
    }

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
