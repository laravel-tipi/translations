<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Actions\CreateTranslation;
use Tipi\Translations\Contracts\HasTranslationStates;
use Tipi\Translations\Contracts\TranslatableModel;
use Tipi\Translations\Tests\Fixtures\Models\DedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingDedicatedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\SoftDeletingStatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\TranslationStateManager;

dataset('state cleanup models', [
    'dedicated' => fn () => DedicatedArticle::query()->create(),
    'shared' => fn () => SharedArticle::query()->create(),
    'json' => fn () => StatefulJsonArticle::query()->create(),
]);

dataset('soft deleting state cleanup models', [
    'dedicated' => fn () => SoftDeletingDedicatedArticle::query()->create(),
    'shared' => fn () => SoftDeletingSharedArticle::query()->create(),
    'json' => fn () => SoftDeletingStatefulJsonArticle::query()->create(),
]);

it('deletes only the permanently deleted parents states', function (
    Model&HasTranslationStates&TranslatableModel $article,
): void {
    $other = $article->newInstance();
    $other->save();

    foreach ([$article, $other] as $parent) {
        foreach (['en', 'ka'] as $locale) {
            resolve(CreateTranslation::class)->execute($parent, ['title' => $locale], $locale);
        }
    }

    $article->load('translationStates');
    $article->delete();

    expect($article->translationStates()->count())->toBe(0)
        ->and($article->translationStates)->toHaveCount(0)
        ->and($other->translationStates()->count())->toBe(2);
})->with('state cleanup models');

it('preserves state metadata through soft deletion and restoration', function (
    Model&HasTranslationStates&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute($article, ['title' => 'English'], 'en');
    resolve(CreateTranslation::class)->execute($article, ['title' => 'ქართული'], 'ka');
    $state = resolve(TranslationStateManager::class)->markAsOutdated($article, 'ka');
    $state->status = 'draft';
    $state->save();
    $before = $article->translationStates()->orderBy('id')->get()->toArray();

    $article->delete();

    expect($article->translationStates()->orderBy('id')->get()->toArray())->toBe($before);

    $article->restore();

    expect($article->translationStates()->orderBy('id')->get()->toArray())->toBe($before);

    $article->delete();
    $article->forceDelete();

    expect($article->translationStates()->count())->toBe(0);
})->with('soft deleting state cleanup models');

it('deletes states when force deleting an active parent', function (
    Model&HasTranslationStates&TranslatableModel $article,
): void {
    resolve(CreateTranslation::class)->execute($article, ['title' => 'English'], 'en');

    $article->forceDelete();

    expect($article->translationStates()->count())->toBe(0);
})->with('soft deleting state cleanup models');
