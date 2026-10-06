<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingDedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingSharedArticle;

it('deletes dedicated translation records when the model is deleted', function (): void {
    $article = DedicatedArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    expect(DB::table('dedicated_article_translations')->count())->toBe(1);

    $article->delete();

    expect(DB::table('dedicated_article_translations')->count())->toBe(0);
});

it('deletes shared translation records when the model is deleted', function (): void {
    $article = SharedArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    expect(DB::table('translations')->count())->toBe(1);

    $article->delete();

    expect(DB::table('translations')->count())->toBe(0);
});

it('preserves dedicated translations when the model is soft deleted', function (): void {
    $article = SoftDeletingDedicatedArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $article->delete();

    expect(DB::table('soft_deleting_dedicated_article_translations')->count())
        ->toBe(1);
});

it('preserves shared translations when the model is soft deleted', function (): void {
    $article = SoftDeletingSharedArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $article->delete();

    expect(DB::table('translations')->count())->toBe(1);
});

it('deletes dedicated translations when the model is force deleted', function (): void {
    $article = SoftDeletingDedicatedArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $article->forceDelete();

    expect(
        DB::table('soft_deleting_dedicated_article_translations')->count(),
    )->toBe(0);
});

it('deletes shared translations when the model is force deleted', function (): void {
    $article = SoftDeletingSharedArticle::query()->create();

    resolve(CreateTranslation::class)->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $article->forceDelete();

    expect(DB::table('translations')->count())->toBe(0);
});
