<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tipi\Translations\Concerns\HasDedicatedTableTranslations;
use Tipi\Translations\Contracts\DedicatedTableTranslatableModel;

final class SoftDeletingDedicatedArticle extends Model implements DedicatedTableTranslatableModel
{
    use HasDedicatedTableTranslations;
    use SoftDeletes;

    protected $table = 'soft_deleting_dedicated_articles';

    protected static array $translatableAttributes = [
        'title',
        'description',
    ];
}
