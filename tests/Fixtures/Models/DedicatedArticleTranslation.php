<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\IsTranslation;
use Tipi\Translations\Contracts\TranslationModel;

final class DedicatedArticleTranslation extends Model implements TranslationModel
{
    use IsTranslation;

    protected function casts(): array
    {
        return [
            'description' => 'array',
        ];
    }
}
