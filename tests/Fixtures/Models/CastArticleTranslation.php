<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class CastArticleTranslation extends Model
{
    protected $table = 'cast_article_translations';

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'position' => 'integer',
        ];
    }
}
