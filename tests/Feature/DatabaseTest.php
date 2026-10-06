<?php

use Illuminate\Support\Facades\Schema;

it('boots the test database schema', function (): void {
    expect(Schema::hasTable('dedicated_articles'))->toBeTrue()
        ->and(Schema::hasTable('dedicated_article_translations'))->toBeTrue()
        ->and(Schema::hasTable('shared_articles'))->toBeTrue()
        ->and(Schema::hasTable('json_articles'))->toBeTrue()
        ->and(Schema::hasTable('translations'))->toBeTrue();
});
