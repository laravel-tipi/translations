<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Touches its parent article when saved.
 */
class TouchingTableArticle extends TableArticle
{
    protected $touches = ['parent'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TableArticle::class, 'parent_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(TableArticleTranslation::class, 'table_article_id');
    }
}
