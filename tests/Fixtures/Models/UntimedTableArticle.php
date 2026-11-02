<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Table storage without timestamps on the model.
 */
class UntimedTableArticle extends TableArticle
{
    public $timestamps = false;

    public function translations(): HasMany
    {
        return $this->hasMany(TableArticleTranslation::class, 'table_article_id');
    }
}
