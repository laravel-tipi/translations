<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tipi\Translations\Tests\Fixtures\Models\CastArticle;
use Tipi\Translations\Tests\Fixtures\Models\CastArticleTranslation;
use Tipi\Translations\Tests\Fixtures\Models\JsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticle;
use Tipi\Translations\Tests\Fixtures\Models\TableArticleTranslation;
use Tipi\Translations\Tests\Fixtures\Models\UntimedJsonArticle;
use Tipi\Translations\Tests\Fixtures\Models\UntimedTableArticle;
use Tipi\Translations\Tests\TestCase;

/*
 | Real concurrency: each writer runs in its own process with its own
 | database connection. Writers load their copy, wait until every writer
 | has loaded, then save at the same time.
 */

beforeEach(function (): void {
    if (TestCase::databaseDriver() === 'sqlite') {
        $this->markTestSkipped('An in-memory SQLite database cannot be shared between processes.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires the pcntl extension.');
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

it('leaves no orphaned rows when a model is deleted during a translation write', function (): void {
    $article = article(CastArticle::class, ['en' => ['position' => 1]]);

    $errors = concurrently([
        writer(CastArticle::class, $article->getKey(), fn ($copy) => $copy->setTranslation('position', 'ka', 2), hold: 200),
        function (Closure $sync) use ($article): void {
            $copy = CastArticle::query()->findOrFail($article->getKey());
            $sync();
            usleep(50_000);
            $copy->delete();
        },
    ]);

    expect($errors[1])->toBeNull()
        ->and(CastArticle::query()->count())->toBe(0)
        ->and(CastArticleTranslation::query()->count())->toBe(0);
});
