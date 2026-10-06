<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Concerns\HasDedicatedTableTranslations;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;

final class DedicatedArticle extends Model implements DedicatedTableTranslatableModel
{
    use HasDedicatedTableTranslations;

    protected $table = 'dedicated_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}