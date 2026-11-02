<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tipi\Translations\Tests\Fixtures\Models\CustomSaveJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\CustomSaveTableArticle;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\Fixtures\Models\UntimedJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\UntimedTableArticle;
use Tipi\Translations\Tests\TestCase;

/*
 | The save lifecycle contract:
 |
 | - translations changed before the row is written (in "saving",
 |   "creating" and "updating" listeners) are written by the same save;
 | - "created", "updated" and "saved" listeners see them stored, and see
 |   them dirty and changed exactly like columns;
 | - translations changed in those listeners stay pending until the next
 |   save();
 | - a failed save keeps every change pending.
 */

afterEach(function (): void {
    foreach ([JsonArticle::class, TableArticle::class, UntimedJsonArticle::class, UntimedTableArticle::class, StatefulJsonArticle::class] as $model) {
        $model::flushEventListeners();
    }
});

it('writes translations changed by listeners that run before the row is written', function (string $class, string $event): void {
    $class::{$event}(function (Model $model) use ($event): void {
        $model->setTranslation('body', 'ka', "set in {$event}");
    });

    $article = $event === 'updating' ? reload(article($class, ['en' => ['title' => 'Saperavi']])) : new $class;
    $article->setTranslation('title', 'en', 'Changed')->save();

    expect(reload($article)->getTranslation('body', 'ka'))->toBe("set in {$event}")
        ->and($article->isDirty())->toBeFalse()
        ->and($article->getDirtyTranslations())->toBe([]);
})->with('models')->with(['saving', 'creating', 'updating']);

it('lets a saving listener make an otherwise clean model save its translations', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $class::saving(fn (Model $model) => $model->setTranslation('body', 'en', 'Red'));

    $article->save();

    expect(reload($article)->getTranslation('body', 'en'))->toBe('Red')
        ->and($article->isDirty())->toBeFalse();
})->with('models');

it('rolls back translations a saving listener added when the save fails', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $class::saving(fn (Model $model) => $model->setTranslation('body', 'en', 'Red'));
    $class::saved(fn () => throw new RuntimeException('Failed.'));

    expect(fn () => $article->save())->toThrow(RuntimeException::class)
        ->and(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']])
        ->and($article->getDirtyTranslations())->toBe(['en' => ['body' => 'Red']]);
})->with('models');

it('has translations stored when created, updated and saved listeners run', function (string $class): void {
    $seen = [];

    foreach (['created', 'updated', 'saved'] as $event) {
        $class::{$event}(function (Model $model) use (&$seen, $event): void {
            $seen[$event][] = $model->newQueryWithoutScopes()->findOrFail($model->getKey())->getTranslation('title', 'en');
        });
    }

    $article = (new $class)->setTranslation('title', 'en', 'Saperavi');
    $article->save();
    $article->setTranslation('title', 'en', 'Mtsvane')->save();

    expect($seen)->toBe([
        'created' => ['Saperavi'],
        'saved' => ['Saperavi', 'Mtsvane'],
        'updated' => ['Mtsvane'],
    ]);
})->with('models');

it('fires the update events once, in order, when only translations change', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $events = [];
    foreach (['saving', 'updating', 'updated', 'saved'] as $event) {
        $class::{$event}(function () use (&$events, $event): void {
            $events[] = $event;
        });
    }

    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect($events)->toBe(['saving', 'updating', 'updated', 'saved']);
})->with('models');

it('reports translated attributes dirty and changed in post-write listeners, like columns', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $seen = [];
    foreach (['updated', 'saved'] as $event) {
        $class::{$event}(function (Model $model) use (&$seen, $event): void {
            $seen[$event] = [
                'dirty' => $model->isDirty('title'),
                'changed' => $model->wasChanged('title'),
                'slug dirty' => $model->isDirty('slug'),
                'slug changed' => $model->wasChanged('slug'),
            ];
        });
    }

    $article->slug = 'saperavi';
    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    $expected = ['dirty' => true, 'changed' => true, 'slug dirty' => true, 'slug changed' => true];

    expect($seen)->toBe(['updated' => $expected, 'saved' => $expected])
        ->and($article->isDirty())->toBeFalse()
        ->and($article->wasChanged('title'))->toBeTrue()
        ->and($article->wasChanged('body'))->toBeFalse();
})->with('models');

it('reports translation-only changes with wasChanged(), with and without timestamps', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect($article->wasChanged('title'))->toBeTrue()
        ->and($article->wasChanged())->toBeTrue()
        ->and($article->wasChanged('body'))->toBeFalse()
        ->and($article->isClean())->toBeTrue();

    $article->slug = 'saperavi';
    $article->save();

    expect($article->wasChanged('title'))->toBeFalse()
        ->and($article->wasChanged('slug'))->toBeTrue();
})->with('models');

it('does not count an insert as a change, like columns', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']], ['slug' => 'saperavi']);

    expect($article->wasChanged('title'))->toBeFalse()
        ->and($article->wasChanged('slug'))->toBeFalse()
        ->and($article->isDirty())->toBeFalse();
})->with('models');

it('keeps translations changed by post-write listeners pending until the next save', function (string $class, string $event): void {
    $class::{$event}(function (Model $model): void {
        if (! $model->hasTranslation('body', 'en')) {
            $model->setTranslation('body', 'en', 'Set by a listener');
        }
    });

    $article = $event === 'created' ? new $class : reload(article($class, ['en' => ['title' => 'Saperavi']]));
    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect(reload($article)->getTranslation('title', 'ka'))->toBe('საფერავი')
        ->and(reload($article)->getTranslation('body', 'en'))->toBeNull()
        ->and($article->isDirty('body'))->toBeTrue()
        ->and($article->isDirty('title'))->toBeFalse()
        ->and($article->getDirtyTranslations())->toBe(['en' => ['body' => 'Set by a listener']]);

    $article->save();

    expect(reload($article)->getTranslation('body', 'en'))->toBe('Set by a listener')
        ->and($article->isDirty())->toBeFalse();
})->with('models')->with(['created', 'updated', 'saved']);

it('writes a post-write listener change once when the listener saves again', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $saves = 0;
    $class::saved(function (Model $model) use (&$saves): void {
        $saves++;

        if (! $model->hasTranslation('body', 'en')) {
            $model->setTranslation('body', 'en', 'Saved by a listener')->saveQuietly();
        }
    });

    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect($saves)->toBe(1)
        ->and(reload($article)->getAllTranslations())->toBe([
            'en' => ['title' => 'Saperavi', 'body' => 'Saved by a listener'],
            'ka' => ['title' => 'საფერავი'],
        ])
        ->and($article->isDirty())->toBeFalse()
        ->and($article->getDirtyTranslations())->toBe([]);
})->with('models');

it('keeps every change pending when a listener fails, and saves it on retry', function (string $class, string $event): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));

    $fail = true;
    $class::{$event}(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('Failed.');
        }
    });

    $article->setTranslation('title', 'ka', 'საფერავი')->setTranslation('title', 'en', 'Changed');

    expect(fn () => $article->save())->toThrow(RuntimeException::class)
        ->and(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']])
        ->and($article->getDirtyTranslations())->toBe(['en' => ['title' => 'Changed'], 'ka' => ['title' => 'საფერავი']])
        ->and($article->isDirty('title'))->toBeTrue();

    $fail = false;
    $article->save();

    expect(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Changed'], 'ka' => ['title' => 'საფერავი']])
        ->and($article->isDirty())->toBeFalse();
})->with('models')->with(['updating', 'updated', 'saved']);

it('never writes a failed save\'s changes once they are no longer pending', function (): void {
    $article = reload(article(CustomSaveTableArticle::class, ['en' => ['title' => 'Saperavi']]));

    // A model with its own save() has no transaction to recover from.
    $article->setTranslation('title', 'en', 'Changed');
    $article->setAttribute('missing_column', 1);

    expect(fn () => $article->save())->toThrow(QueryException::class);

    $article->setTranslation('title', 'en', 'Saperavi');
    unset($article->missing_column);
    $article->slug = 'changed';
    $article->save();

    expect(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi')
        ->and($article->wasChanged('title'))->toBeFalse();
});

it('leaves a new model new when its save fails, so it can be saved again', function (string $class, string $event): void {
    $fail = true;
    $class::{$event}(function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('Failed.');
        }
    });

    $article = (new $class(['slug' => 'saperavi']))->setTranslation('title', 'en', 'Saperavi');

    expect(fn () => $article->save())->toThrow(RuntimeException::class)
        ->and($article->exists)->toBeFalse()
        ->and($article->wasRecentlyCreated)->toBeFalse()
        ->and($article->getKey())->toBeNull()
        ->and($class::query()->count())->toBe(0);

    $fail = false;
    $article->save();

    expect($class::query()->count())->toBe(1)
        ->and(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi')
        ->and($article->isDirty())->toBeFalse();
})->with('models')->with(['created', 'saved']);

it('reloads translation states after a failed save', function (): void {
    $article = reload(article(StatefulJsonArticle::class, ['en' => ['title' => 'Saperavi']]));
    $article->load('translationStates');

    StatefulJsonArticle::saved(fn () => throw new RuntimeException('Failed.'));

    expect(fn () => $article->setTranslation('title', 'ka', 'საფერავი')->save())->toThrow(RuntimeException::class)
        ->and($article->getTranslationState('ka'))->toBeNull()
        ->and($article->getTranslationState('en'))->not->toBeNull();
});

it('keeps translations pending when a listener cancels the save', function (string $class, string $event): void {
    $class::{$event}(fn () => false);

    $article = (new $class)->setTranslation('title', 'en', 'Saperavi');

    expect($article->save())->toBeFalse()
        ->and($class::query()->count())->toBe(0)
        ->and($article->getDirtyTranslations())->toBe(['en' => ['title' => 'Saperavi']]);
})->with('models')->with(['saving', 'creating']);

it('writes translations with saveOrIgnore()', function (string $class): void {
    if (in_array(TestCase::databaseDriver(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Laravel does not support saveOrIgnore() on MySQL and MariaDB.');
    }

    $article = (new $class(['slug' => 'saperavi']))->setTranslation('title', 'en', 'Saperavi');

    expect($article->saveOrIgnore())->toBeTrue()
        ->and(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi')
        ->and($article->isDirty())->toBeFalse();
})->with('models');

it('writes translations when the model defines its own save()', function (string $class): void {
    $article = (new $class)->setTranslation('title', 'en', 'Saperavi');
    $article->save();

    expect(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi')
        ->and($article->slug)->toBe('custom')
        ->and($article->isDirty())->toBeFalse();

    $article->setTranslation('title', 'ka', 'საფერავი')->save();

    expect(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']]);
})->with([CustomSaveJsonArticle::class, CustomSaveTableArticle::class]);

it('follows the same rules for observer classes', function (string $class): void {
    $observer = new class
    {
        /** @var list<string|null> */
        public static array $seen = [];

        public function creating(Model $model): void
        {
            $model->setTranslation('body', 'en', 'From the observer');
        }

        public function created(Model $model): void
        {
            self::$seen[] = $model->newQueryWithoutScopes()->findOrFail($model->getKey())->getTranslation('body', 'en');
        }
    };
    $observer::$seen = [];

    $class::observe($observer);

    $article = (new $class)->setTranslation('title', 'en', 'Saperavi');
    $article->save();

    expect($observer::$seen)->toBe(['From the observer'])
        ->and(reload($article)->getTranslations('en'))->toBe(['title' => 'Saperavi', 'body' => 'From the observer']);
})->with('models');

it('rolls back only its own savepoint when a failed save is caught inside a transaction', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));
    $other = reload(article($class, ['en' => ['title' => 'Mtsvane']]));

    $class::saved(function (Model $model) use ($article): void {
        if ($model->is($article)) {
            throw new RuntimeException('Failed.');
        }
    });

    DB::transaction(function () use ($article, $other): void {
        try {
            $article->setTranslation('title', 'ka', 'საფერავი')->save();
        } catch (RuntimeException) {
            // Handled: the transaction goes on.
        }

        $other->setTranslation('title', 'ka', 'მწვანე')->save();
    });

    expect(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi']])
        ->and($article->getDirtyTranslations())->toBe(['ka' => ['title' => 'საფერავი']])
        ->and(reload($other)->getTranslation('title', 'ka'))->toBe('მწვანე');
})->with('models');
