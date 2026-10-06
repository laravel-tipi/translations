<?php

declare(strict_types=1);

namespace Tipi\Translations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property array<string, mixed> $values
 * @property string $locale_code
 */
#[Fillable([
    'locale_code',
    'values',
    'outdated_at',
])]
class TranslationModel extends Model
{
    protected $table = 'translations';

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'outdated_at' => 'immutable_datetime',
        ];
    }

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
