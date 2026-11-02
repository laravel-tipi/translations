<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticleTranslation;
use Tipi\Translations\Tests\Fixtures\Models\TouchingTableArticle;

afterEach(function (): void {
    foreach ([JsonArticle::class, TableArticle::class, TableArticleTranslation::class] as $model) {
        $model::flushEventListeners();
    }
});

it('writes translations with saveOrFail() and rolls them back on failure', function (string $class): void {
    $article = (new $class)->setTranslation('title', 'en', 'Saperavi');

    expect($article->saveOrFail())->toBeTrue()
        ->and(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi');

    $class::saved(fn () => throw new RuntimeException('Failed.'));

    expect(fn () => (new $class)->setTranslation('title', 'en', 'Mtsvane')->saveOrFail())->toThrow(RuntimeException::class)
        ->and($class::query()->count())->toBe(1);
})->with('storages');

it('writes translations with updateOrCreate(), firstOrCreate() and updateQuietly()', function (string $class): void {
    $created = $class::firstOrCreate(['slug' => 'saperavi'], ['title' => 'Saperavi']);
    $updated = $class::updateOrCreate(['slug' => 'saperavi'], ['body' => 'Red']);

    useLocale('ka');
    $updated->updateQuietly(['title' => 'საფერავი']);

    expect($updated->is($created))->toBeTrue()
        ->and(reload($created)->getAllTranslations())->toBe([
            'en' => ['title' => 'Saperavi', 'body' => 'Red'],
            'ka' => ['title' => 'საფერავი'],
        ]);
})->with('storages');

it('writes translations through a model factory', function (string $class): void {
    $factory = new class extends Factory
    {
        public static string $class;

        public function modelName(): string
        {
            return self::$class;
        }

        public function definition(): array
        {
            return ['slug' => 'saperavi', 'title' => 'Saperavi'];
        }
    };
    $factory::$class = $class;

    $made = $factory->make(['body' => 'Red']);
    $created = $factory->count(2)->create();

    expect($made->exists)->toBeFalse()
        ->and($made->getTranslations('en'))->toBe(['title' => 'Saperavi', 'body' => 'Red'])
        ->and($created->map(fn ($article) => reload($article)->getTranslation('title', 'en'))->all())->toBe(['Saperavi', 'Saperavi']);
})->with('storages');

it('writes translations with push()', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $article->setTranslation('title', 'ka', 'საფერავი')->push();

    expect(reload($article)->getTranslation('title', 'ka'))->toBe('საფერავი');
})->with('storages');

it('fires update events when only translations change, for both storages', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $events = [];
    foreach (['saving', 'updating', 'updated', 'saved'] as $event) {
        $class::{$event}(function () use (&$events, $event): void {
            $events[] = $event;
        });
    }

    $this->travel(1)->minute();
    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect($events)->toBe(['saving', 'updating', 'updated', 'saved'])
        ->and($article->wasChanged('updated_at'))->toBeTrue();
})->with('storages');

it('keeps translation changes made by listeners during save pending instead of losing them', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $class::saved(function ($model): void {
        if (! $model->hasTranslation('body', 'en')) {
            $model->setTranslation('body', 'en', 'Set by a listener');
        }
    });

    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect(reload($article)->getTranslation('title', 'ka'))->toBe('საფერავი')
        ->and($article->isDirty('body'))->toBeTrue()
        ->and($article->getTranslation('body', 'en'))->toBe('Set by a listener');

    $article->save();

    expect(reload($article)->getTranslation('body', 'en'))->toBe('Set by a listener')
        ->and($article->isDirty())->toBeFalse();
})->with('storages');

it('handles a listener that saves the model again', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $class::saved(function ($model): void {
        if (! $model->hasTranslation('body', 'en')) {
            $model->setTranslation('body', 'en', 'Saved by a listener')->save();
        }
    });

    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect(reload($article)->getAllTranslations())->toBe([
        'en' => ['title' => 'Saperavi', 'body' => 'Saved by a listener'],
        'ka' => ['title' => 'საფერავი'],
    ])->and($article->isDirty())->toBeFalse();
})->with('storages');

it('discards translation changes with discardChanges()', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $article->setTranslation('title', 'en', 'Changed')->discardChanges();

    expect($article->isDirty())->toBeFalse()
        ->and($article->title)->toBe('Saperavi');

    $article->save();

    expect(reload($article)->title)->toBe('Saperavi');
})->with('storages');

it('touches owners when only table-stored translations change', function (): void {
    $this->travelTo('2026-01-01 10:00:00');
    $parent = article(TableArticle::class, ['en' => ['title' => 'Parent']]);
    $child = TouchingTableArticle::query()->findOrFail(
        article(TouchingTableArticle::class, ['en' => ['title' => 'Child']], ['parent_id' => $parent->id])->id,
    );

    $this->travelTo('2026-01-02 10:00:00');
    $child->setTranslation('title', 'ka', 'შვილი')->save();

    expect(reload($parent)->updated_at->toDateTimeString())->toBe('2026-01-02 10:00:00');
});
