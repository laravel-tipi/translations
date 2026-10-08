<?php

declare(strict_types=1);

namespace Tipi\Translations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Tipi\Translations\Config\TranslationConfig;

#[Fillable([
    'locale_code',
    'status',
    'outdated_at',
])]
class TranslationState extends Model
{
    public function getTable(): string
    {
        return resolve(TranslationConfig::class)->translationStatesTable;
    }

    protected function casts(): array
    {
        $statusEnum = resolve(TranslationConfig::class)
            ->translationStateStatusEnum;

        return [
            'outdated_at' => 'immutable_datetime',
            ...($statusEnum !== null
                ? ['status' => $statusEnum]
                : []),
        ];
    }

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
