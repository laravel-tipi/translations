<?php

declare(strict_types=1);

namespace Tipi\Translations\Tests\Fixtures\Models;

/**
 * Defines its own save(), which replaces the trait's.
 */
class CustomSaveSharedArticle extends SharedArticle
{
    public function save(array $options = []): bool
    {
        $this->slug ??= 'custom';

        return parent::save($options);
    }
}
