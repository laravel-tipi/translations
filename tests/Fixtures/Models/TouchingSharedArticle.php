<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Touches its parent article when saved.
 */
class TouchingSharedArticle extends SharedArticle
{
    protected $touches = ['parent'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(SharedArticle::class, 'parent_id');
    }
}
