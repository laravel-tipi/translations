<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingDedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingSharedArticle;

dataset('soft deletable table translatable models', [
    'dedicated table' => fn () => new SoftDeletingDedicatedArticle,
    'shared table' => fn () => new SoftDeletingSharedArticle,
]);

dataset('hard deletable table translatable models', [
    'dedicated table' => fn () => new DedicatedArticle,
    'shared table' => fn () => new SharedArticle,
]);

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

it('preserves all translations when a translatable model is soft deleted', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    $article->delete();

    expect($article->translationExists('en'))->toBeTrue()
        ->and($article->translationExists('ka'))->toBeTrue();
})->with('soft deletable table translatable models');

it('restores access to all translations after restoring a soft deleted model', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    $article->delete();
    $article->restore();

    $article->refresh();

    expect($article->translationExists('en'))->toBeTrue()
        ->and($article->translationExists('ka'))->toBeTrue()
        ->and($article->getTranslation('en')?->attributes['title'])
        ->toBe('English')
        ->and($article->getTranslation('ka')?->attributes['title'])
        ->toBe('ქართული');
})->with('soft deletable table translatable models');

it('removes all translations when a soft deletable model is force deleted', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    $article->forceDelete();

    expect($article->translationRecords()->count())->toBe(0);
})->with('soft deletable table translatable models');

it('removes all translations when a translatable model is hard deleted', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    $article->delete();

    expect($article->translationRecords()->count())->toBe(0);
})->with('hard deletable table translatable models');

it('removes preserved translations when a soft deleted model is later force deleted', function (
    Model&TranslatableModel $article,
): void {
    $article->save();

    $create = resolve(CreateTranslation::class);

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'English'],
        localeCode: 'en',
    );

    $create->execute(
        translatable: $article,
        attributes: ['title' => 'ქართული'],
        localeCode: 'ka',
    );

    $article->delete();

    expect($article->translationRecords()->count())->toBe(2);

    $article->forceDelete();

    expect($article->translationRecords()->count())->toBe(0);
})->with('soft deletable table translatable models');
