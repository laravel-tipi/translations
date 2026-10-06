<?php

declare(strict_types=1);

namespace Tipi\Translations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TranslationModel extends Model
{
    protected $table = 'translations';

    protected function casts(): array
    {
        return [
            'outdated_at' => 'immutable_datetime',
        ];
    }

    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
