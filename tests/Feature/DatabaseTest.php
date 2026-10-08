<?php

use Illuminate\Support\Facades\Schema;

it('boots the test database schema', function (): void {
    expect(Schema::hasTable('dedicated_articles'))->toBeTrue()
        ->and(Schema::hasTable('dedicated_article_translations'))->toBeTrue()
        ->and(Schema::hasTable('shared_articles'))->toBeTrue()
        ->and(Schema::hasTable('json_articles'))->toBeTrue()
        ->and(Schema::hasTable('translations'))->toBeTrue()
        ->and(Schema::hasTable('translation_states'))->toBeTrue();
});

it('stores outdated timestamps exclusively in translation states', function (): void {
    expect(Schema::hasColumn('translation_states', 'outdated_at'))->toBeTrue();

    foreach (['translations', 'dedicated_article_translations', 'soft_deleting_dedicated_article_translations'] as $table) {
        expect(Schema::hasColumn($table, 'outdated_at'))->toBeFalse();
    }
});
