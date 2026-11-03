<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tipi\Translations\Models\Translation;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;

/*
 | The package never retries: save() and delete() run their transaction
 | once, so listeners and their side effects run once per call. Retrying
 | is the application's decision (DB::transaction($callback, attempts:)),
 | and then everything in the callback runs again, events included.
 |
 | A deadlock is simulated with the error MySQL reports, so these tests
 | run on every database; ConcurrencyTest covers real ones.
 */

afterEach(function (): void {
    foreach ([StatefulJsonArticle::class, StatefulTableArticle::class, StatefulSharedArticle::class] as $model) {
        $model::flushEventListeners();
    }
});

dataset('retry storages', [
    'json' => StatefulJsonArticle::class,
    'table' => StatefulTableArticle::class,
    'shared' => StatefulSharedArticle::class,
]);

function deadlock(): QueryException
{
    return new QueryException('testing', 'update ...', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
}

/**
 * Count the model's events; the listener for $failOn throws a deadlock
 * the first $failures times it runs.
 *
 * @param  class-string<Model>  $class
 * @return ArrayObject<string, int>
 */
function countEvents(string $class, string $failOn, int $failures = 1): ArrayObject
{
    $counts = new ArrayObject;

    foreach (['saving', 'updating', 'updated', 'saved', 'deleting', 'deleted'] as $event) {
        $class::{$event}(function () use ($counts, $event, $failOn, &$failures): void {
            $counts[$event] = ($counts[$event] ?? 0) + 1;

            if ($event === $failOn && $failures-- > 0) {
                throw deadlock();
            }
        });
    }

    return $counts;
}

it('does not retry a save that hits a deadlock', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));
    $events = countEvents($class, 'saved');

    $article->setTranslation('title', 'ka', 'საფერავი');

    expect(fn () => $article->save())->toThrow(QueryException::class, 'Deadlock found')
        ->and($events->getArrayCopy())->toBe(['saving' => 1, 'updating' => 1, 'updated' => 1, 'saved' => 1])
        ->and(reload($article)->getTranslation('title', 'ka'))->toBeNull()
        ->and($article->getDirtyTranslations())->toBe(['ka' => ['title' => 'საფერავი']]);

    // Retrying is the caller's call: the pending change is saved once.
    expect($article->save())->toBeTrue()
        ->and($events['saved'])->toBe(2)
        ->and(reload($article)->getTranslation('title', 'ka'))->toBe('საფერავი');
})->with('retry storages');

it('does not retry a save inside a transaction that hits a deadlock', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));
    $events = countEvents($class, 'saved');

    expect(fn () => DB::transaction(fn () => $article->setTranslation('title', 'ka', 'საფერავი')->save()))
        ->toThrow(DeadlockException::class)
        ->and($events['saved'])->toBe(1)
        ->and(DB::transactionLevel())->toBe(0)
        ->and(reload($article)->getTranslation('title', 'ka'))->toBeNull()
        ->and($article->getDirtyTranslations())->toBe(['ka' => ['title' => 'საფერავი']]);
})->with('retry storages');

it('does not retry a delete that hits a deadlock', function (string $class): void {
    $article = reload(article($class, ['en' => ['title' => 'Saperavi']]));
    $events = countEvents($class, 'deleted');

    expect(fn () => $article->forceDelete())->toThrow(QueryException::class, 'Deadlock found')
        ->and($events->getArrayCopy())->toBe(['deleting' => 1, 'deleted' => 1])
        ->and(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi');
})->with('retry storages');

it('runs the whole callback again, events included, when the application retries', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);
    $events = countEvents($class, 'saved');
    $committed = 0;

    DB::transaction(function () use ($class, $article, &$committed): void {
        // Load inside the callback: each attempt starts from the database.
        $class::query()->findOrFail($article->getKey())->setTranslation('title', 'ka', 'საფერავი')->save();

        // Side effects that must happen once belong after the commit.
        DB::afterCommit(function () use (&$committed): void {
            $committed++;
        });
    }, attempts: 2);

    expect($events['saving'])->toBe(2)
        ->and($events['saved'])->toBe(2)
        ->and($committed)->toBe(1)
        ->and(reload($article)->getAllTranslations())->toBe(['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'საფერავი']])
        ->and(reload($article)->getTranslationState('ka'))->not->toBeNull();

    if ($class === StatefulSharedArticle::class) {
        expect(Translation::query()->count())->toBe(2);
    }
})->with('retry storages');
