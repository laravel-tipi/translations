<?php

declare(strict_types=1);

use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tipi\Translations\Models\Translation;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\Tests\Fixtures\Models\CastArticle;
use Tipi\Translations\Tests\Fixtures\Models\CastArticleTranslation;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\SharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\StatefulTableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticleTranslation;
use Tipi\Translations\Tests\Fixtures\Models\UntimedJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\UntimedSharedArticle;
use Tipi\Translations\Tests\Fixtures\Models\UntimedTableArticle;
use Tipi\Translations\Tests\TestCase;

/*
 | Real concurrency: each writer runs in its own process with its own
 | database connection. Writers load their copy, wait until every writer
 | has loaded, then save at the same time.
 */

beforeEach(function (): void {
    // The only skip: the same tests run on MySQL, MariaDB and PostgreSQL.
    if (TestCase::databaseDriver() === 'sqlite') {
        $this->markTestSkipped('An in-memory SQLite database cannot be shared between processes.');
    }
});

/**
 * Run each writer in its own process. A writer receives a $sync callback
 * that waits until every writer reached it. Returns the error each
 * writer threw, or null.
 *
 * @param  list<Closure(Closure): void>  $writers
 * @return list<string|null>
 */
function concurrently(array $writers): array
{
    $directory = sys_get_temp_dir().'/tipi-concurrency-'.bin2hex(random_bytes(6));
    mkdir($directory);

    // Children must not share the parent's connection.
    DB::disconnect();

    $pids = [];

    foreach ($writers as $index => $writer) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not fork.');
        }

        if ($pid === 0) {
            $points = 0;
            $sync = function () use ($directory, $index, &$points, $writers): void {
                $point = $points++;
                touch("{$directory}/sync-{$point}-{$index}");

                $deadline = microtime(true) + 20;

                while (count(glob("{$directory}/sync-{$point}-*")) < count($writers)) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('Timed out waiting for the other writers.');
                    }

                    usleep(2000);
                }
            };

            try {
                $writer($sync);
                $result = '';
            } catch (Throwable $exception) {
                $result = $exception::class.': '.$exception->getMessage();
            }

            file_put_contents("{$directory}/result-{$index}", $result);
            DB::disconnect();

            // Skip PHPUnit's shutdown handlers in the child.
            posix_kill(getmypid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];

    foreach (array_keys($writers) as $index) {
        $result = @file_get_contents("{$directory}/result-{$index}");
        $results[] = $result === false ? 'The writer did not finish.' : ($result === '' ? null : $result);
    }

    array_map('unlink', glob("{$directory}/*"));
    rmdir($directory);

    return $results;
}

/**
 * A writer that loads its copy, waits for the others, then applies the
 * change and saves. $hold keeps its transaction open after writing, so
 * the other writers run into its locks.
 *
 * @param  class-string<Model>  $class
 * @return Closure(Closure): void
 */
function writer(string $class, int|string $id, Closure $change, int $hold = 0): Closure
{
    return function (Closure $sync) use ($class, $id, $change, $hold): void {
        if ($hold > 0) {
            $class::saved(fn () => usleep($hold * 1000));
        }

        $copy = $class::query()->findOrFail($id);
        $copy->getAllTranslations();

        $sync();

        $change($copy);
        $copy->save();
    };
}

dataset('concurrent models', [
    'json' => JsonArticle::class,
    'table' => TableArticle::class,
    'json without timestamps' => UntimedJsonArticle::class,
    'table without timestamps' => UntimedTableArticle::class,
    'shared' => SharedArticle::class,
    'shared without timestamps' => UntimedSharedArticle::class,
]);

it('keeps concurrent writes of different locales', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $errors = concurrently([
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('title', 'ka', 'საფერავი'), hold: 300),
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('title', 'de', 'Saperawi'), hold: 300),
    ]);

    expect($errors)->toBe([null, null])
        ->and(reload($article)->getAllTranslations())->toBe([
            'en' => ['title' => 'Saperavi'],
            'ka' => ['title' => 'საფერავი'],
            'de' => ['title' => 'Saperawi'],
        ]);
})->with('concurrent models');

it('keeps concurrent writes of different attributes of one locale', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $errors = concurrently([
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('title', 'en', 'Saperavi 2020'), hold: 300),
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('body', 'en', 'Dark red'), hold: 300),
    ]);

    expect($errors)->toBe([null, null])
        ->and(reload($article)->getTranslations('en'))->toBe(['title' => 'Saperavi 2020', 'body' => 'Dark red']);
})->with('concurrent models');

it('inserts a missing locale once when two writers add it at the same time', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $errors = concurrently([
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('title', 'ka', 'საფერავი')),
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('body', 'ka', 'წითელი')),
    ]);

    expect($errors)->toBe([null, null])
        ->and(reload($article)->getTranslations('ka'))->toBe(['title' => 'საფერავი', 'body' => 'წითელი']);

    if (is_a($class, TableArticle::class, true)) {
        expect(TableArticleTranslation::query()->where('locale_code', 'ka')->count())->toBe(1);
    }
})->with('concurrent models');

it('lets the last committed write win for the same value', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $errors = concurrently([
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('title', 'ka', 'first'), hold: 200),
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('title', 'ka', 'second'), hold: 200),
    ]);

    expect($errors)->toBe([null, null])
        ->and(reload($article)->getTranslation('title', 'ka'))->toBeIn(['first', 'second'])
        ->and(reload($article)->getTranslation('title', 'en'))->toBe('Saperavi');
})->with('concurrent models');

it('keeps a value written while another writer empties the locale', function (string $class): void {
    $article = article($class, ['ka' => ['title' => 'საფერავი']]);

    $errors = concurrently([
        writer($class, $article->getKey(), fn ($copy) => $copy->forgetTranslation('title', 'ka')),
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation('body', 'ka', 'წითელი')),
    ]);

    expect($errors)->toBe([null, null])
        ->and(reload($article)->getAllTranslations())->toBe(['ka' => ['body' => 'წითელი']]);
})->with('concurrent models');

it('stays consistent under many concurrent writers', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $writers = [];

    foreach (['en', 'ka', 'de', 'fr'] as $locale) {
        $writers[] = function (Closure $sync) use ($class, $article, $locale): void {
            $sync();

            for ($round = 1; $round <= 5; $round++) {
                $copy = $class::query()->findOrFail($article->getKey());
                $copy->setTranslations($locale, ['title' => "{$locale} {$round}", 'body' => "{$locale} body {$round}"])->save();
            }
        };
    }

    expect(concurrently($writers))->toBe([null, null, null, null])
        ->and(reload($article)->getAllTranslations())->toBe([
            'en' => ['title' => 'en 5', 'body' => 'en body 5'],
            'ka' => ['title' => 'ka 5', 'body' => 'ka body 5'],
            'de' => ['title' => 'de 5', 'body' => 'de body 5'],
            'fr' => ['title' => 'fr 5', 'body' => 'fr body 5'],
        ]);
})->with('concurrent models');

/**
 * Translation rows and states left in the database.
 */
function remainingRows(): int
{
    return CastArticleTranslation::query()->count()
        + TableArticleTranslation::query()->count()
        + Translation::query()->count()
        + TranslationState::query()->count();
}

dataset('deletable models', [
    'table with a foreign key' => CastArticle::class,
    'table without a foreign key' => UntimedTableArticle::class,
    'shared' => SharedArticle::class,
    'shared without timestamps' => UntimedSharedArticle::class,
    'shared with states' => StatefulSharedArticle::class,
    'json with states' => StatefulJsonArticle::class,
]);

/**
 * A value to write for the class's first translatable attribute.
 *
 * @param  class-string<Model>  $class
 * @return array{0: string, 1: mixed}
 */
function firstAttribute(string $class): array
{
    $attribute = (new $class)->getTranslatableAttributes()[0];

    return [$attribute, $attribute === 'position' ? 2 : 'Value'];
}

it('leaves no rows behind when a model is deleted while a writer adds rows', function (string $class): void {
    [$attribute, $value] = firstAttribute($class);
    $article = article($class, ['en' => [$attribute => $value]]);

    $errors = concurrently([
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslation($attribute, 'ka', $value), hold: 300),
        function (Closure $sync) use ($class, $article): void {
            $copy = $class::query()->findOrFail($article->getKey());
            $sync();
            usleep(100_000);
            method_exists($copy, 'forceDelete') ? $copy->forceDelete() : $copy->delete();
        },
    ]);

    expect($errors)->toBe([null, null])
        ->and($class::query()->count())->toBe(0)
        ->and(remainingRows())->toBe(0);
})->with('deletable models');

it('refuses rows for a model deleted while the writer waited', function (string $class): void {
    [$attribute, $value] = firstAttribute($class);
    $article = article($class, ['en' => [$attribute => $value]]);

    $errors = concurrently([
        function (Closure $sync) use ($class, $article): void {
            // Hold the delete's transaction open after the row is gone.
            $class::deleted(fn () => usleep(300_000));
            $copy = $class::query()->findOrFail($article->getKey());
            $sync();
            method_exists($copy, 'forceDelete') ? $copy->forceDelete() : $copy->delete();
        },
        function (Closure $sync) use ($class, $article, $attribute, $value): void {
            $copy = $class::query()->findOrFail($article->getKey());
            $copy->getAllTranslations();
            $sync();
            usleep(100_000);
            $copy->setTranslation($attribute, 'ka', $value)->save();
        },
    ]);

    expect($errors[0])->toBeNull()
        ->and($errors[1])->toStartWith(ModelNotFoundException::class)
        ->and($class::query()->count())->toBe(0)
        ->and(remainingRows())->toBe(0);
})->with('deletable models');

it('writes many models sharing one table at the same time without deadlocks', function (string $class): void {
    $existing = [];

    for ($i = 0; $i < 12; $i++) {
        $existing[] = article($class, ['en' => ['title' => "Wine {$i}"]])->getKey();
    }

    $writers = [];

    foreach (['ka', 'de', 'fr', 'en'] as $index => $locale) {
        $writers[] = function (Closure $sync) use ($class, $existing, $locale, $index): void {
            $sync();

            // Every writer adds its locale to every existing model, in its
            // own order, and creates models of its own.
            $keys = $index % 2 === 0 ? $existing : array_reverse($existing);

            foreach ($keys as $key) {
                $class::query()->findOrFail($key)->setTranslations($locale, ['title' => "{$locale} {$key}", 'body' => $locale])->save();
                article($class, [$locale => ['title' => "new {$locale}"], 'en' => ['body' => 'new']]);
            }
        };
    }

    expect(concurrently($writers))->toBe([null, null, null, null]);

    foreach ($existing as $key) {
        expect($class::query()->findOrFail($key)->getTranslatedLocales())->toEqualCanonicalizing(['en', 'ka', 'de', 'fr']);
    }

    expect($class::query()->count())->toBe(12 + 4 * 12);
})->with(['json' => JsonArticle::class, 'table' => TableArticle::class, 'shared' => SharedArticle::class]);

it('recovers from a deadlock between transactions saving two models in opposite order', function (string $class): void {
    $first = article($class, ['en' => ['title' => 'First']])->getKey();
    $second = article($class, ['en' => ['title' => 'Second']])->getKey();
    $attempts = sys_get_temp_dir().'/tipi-attempts-'.bin2hex(random_bytes(4));

    $writer = fn (array $keys, string $locale): Closure => function (Closure $sync) use ($class, $keys, $locale, $attempts): void {
        $synced = false;

        // Laravel retries the closure when the database reports a
        // deadlock; every attempt starts from fresh copies.
        DB::transaction(function () use ($class, $keys, $locale, $sync, &$synced, $attempts): void {
            file_put_contents($attempts, '.', FILE_APPEND);

            foreach ($keys as $position => $key) {
                $class::query()->findOrFail($key)->setTranslation('title', $locale, "{$locale} {$key}")->save();

                if ($position === 0 && ! $synced) {
                    $synced = true;
                    $sync();
                }
            }
        }, attempts: 3);
    };

    $errors = concurrently([$writer([$first, $second], 'ka'), $writer([$second, $first], 'de')]);
    $tries = strlen((string) file_get_contents($attempts));
    unlink($attempts);

    expect($errors)->toBe([null, null])
        ->and($tries)->toBeGreaterThan(2)
        ->and($class::query()->findOrFail($first)->getTranslations('ka'))->toBe(['title' => "ka {$first}"])
        ->and($class::query()->findOrFail($first)->getTranslations('de'))->toBe(['title' => "de {$first}"])
        ->and($class::query()->findOrFail($second)->getTranslatedLocales())->toEqualCanonicalizing(['en', 'ka', 'de']);
})->with(['json' => JsonArticle::class, 'table' => TableArticle::class, 'shared' => SharedArticle::class]);

/*
 | lockTranslationsForUpdate(): read-modify-write without lost updates.
 */

it('loses no read-modify-write of concurrent writers', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => '']]);

    $writers = [];

    foreach (['a', 'b', 'c', 'd'] as $name) {
        $writers[] = function (Closure $sync) use ($class, $article, $name): void {
            $copy = $class::query()->findOrFail($article->getKey());
            $copy->getAllTranslations();
            $sync();

            foreach (range(1, 3) as $round) {
                $copy->lockTranslationsForUpdate(
                    fn ($model) => $model->setTranslation('title', 'ka', $model->getTranslation('title', 'ka').$name),
                );
            }
        };
    }

    expect(concurrently($writers))->toBe([null, null, null, null]);

    $letters = str_split((string) reload($article)->getTranslation('title', 'ka'));
    sort($letters);

    expect(implode('', $letters))->toBe('aaabbbcccddd');
})->with('concurrent models');

it('lets exactly one of two compare-and-set writers win', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi'], 'ka' => ['title' => 'base']]);

    $compareAndSet = fn (string $value): Closure => function (Closure $sync) use ($class, $article, $value): void {
        $copy = $class::query()->findOrFail($article->getKey());
        $expected = $copy->getTranslation('title', 'ka');
        $sync();

        $copy->lockTranslationsForUpdate(function ($model) use ($expected, $value): void {
            if ($model->getTranslation('title', 'ka') !== $expected) {
                throw new RuntimeException('Conflict');
            }

            usleep(200_000);
            $model->setTranslation('title', 'ka', $value);
        });
    };

    $errors = concurrently([$compareAndSet('first'), $compareAndSet('second')]);

    expect(array_values(array_filter($errors)))->toBe(['RuntimeException: Conflict']);

    $winner = $errors[0] === null ? 'first' : 'second';

    expect(reload($article)->getTranslation('title', 'ka'))->toBe($winner);
})->with('concurrent models');

it('never decides from a stale snapshot of a surrounding transaction', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $errors = concurrently([
        function (Closure $sync) use ($class, $article): void {
            DB::transaction(function () use ($sync, $class, $article): void {
                // A plain read first: on MySQL and MariaDB it fixes the
                // transaction's snapshot before the other writer commits.
                $copy = $class::query()->findOrFail($article->getKey());
                $copy->getAllTranslations();

                $sync();
                $sync();

                $seen = $copy->lockTranslationsForUpdate(fn ($model) => $model->getTranslation('title', 'ka'));

                if ($seen !== 'საფერავი') {
                    throw new RuntimeException('Read a stale value: '.var_export($seen, true));
                }
            });
        },
        function (Closure $sync) use ($class, $article): void {
            $sync();
            $class::query()->findOrFail($article->getKey())->setTranslation('title', 'ka', 'საფერავი')->save();
            $sync();
        },
    ]);

    // MySQL and PostgreSQL read the committed value. MariaDB's snapshot
    // isolation refuses the locking read instead, with a deadlock error
    // that rolls the transaction back, so it can be retried.
    expect($errors[1])->toBeNull()
        ->and($errors[0] === null || str_contains($errors[0], 'Record has changed since last read'))->toBeTrue();

    if ($errors[0] !== null) {
        expect($errors[0])->toStartWith(DeadlockException::class)
            ->and(TestCase::databaseDriver())->toBe('mariadb');
    }
})->with('concurrent models');

/*
 | Drafts and sources (HasTranslationStates) merge like translations.
 */

dataset('concurrent stateful models', [
    'json' => StatefulJsonArticle::class,
    'table' => StatefulTableArticle::class,
    'shared' => StatefulSharedArticle::class,
]);

it('keeps concurrent drafts of different attributes of one locale', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi', 'body' => 'Red']]);

    $errors = concurrently([
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslationDraft('title', 'ka', 'სათაური')->setTranslationSource('title', 'ka', 't'), hold: 300),
        writer($class, $article->getKey(), fn ($copy) => $copy->setTranslationDraft('body', 'ka', 'ტექსტი')->setTranslationSource('body', 'ka', 'b'), hold: 300),
    ]);

    expect($errors)->toBe([null, null]);

    $fresh = reload($article);

    expect($fresh->getTranslationDrafts('ka'))->toBe(['title' => 'სათაური', 'body' => 'ტექსტი'])
        ->and([$fresh->getTranslationSource('title', 'ka'), $fresh->getTranslationSource('body', 'ka')])->toBe(['t', 'b']);
})->with('concurrent stateful models');

it('loses no read-modify-write of drafts', function (string $class): void {
    $article = article($class, ['en' => ['title' => 'Saperavi']]);

    $writers = [];

    foreach (['a', 'b', 'c'] as $name) {
        $writers[] = function (Closure $sync) use ($class, $article, $name): void {
            $copy = $class::query()->findOrFail($article->getKey());
            $sync();

            foreach (range(1, 3) as $round) {
                $copy->lockTranslationsForUpdate(
                    fn ($model) => $model->setTranslationDraft('title', 'ka', $model->getTranslationDraft('title', 'ka').$name),
                );
            }
        };
    }

    expect(concurrently($writers))->toBe([null, null, null]);

    $letters = str_split((string) reload($article)->getTranslationDraft('title', 'ka'));
    sort($letters);

    expect(implode('', $letters))->toBe('aaabbbccc');
})->with('concurrent stateful models');
