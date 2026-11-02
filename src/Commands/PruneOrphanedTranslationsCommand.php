<?php

declare(strict_types=1);

namespace Tipi\Translations\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Tipi\Translations\Models\Translation;
use Tipi\Translations\Models\TranslationState;
use Tipi\Translations\Support\MorphKeyType;

/**
 * Deletes shared translations and translation states whose model no
 * longer exists. Models delete their rows themselves; rows are left
 * behind only when models are deleted without Eloquent (query builder
 * deletes, raw SQL, cascades from other tables). Run it after such
 * deletes, or on a schedule.
 */
final class PruneOrphanedTranslationsCommand extends Command
{
    protected $signature = 'translations:prune-orphans
        {--pretend : Count the orphaned rows without deleting them}';

    protected $description = 'Delete shared translations and translation states whose model no longer exists';

    public function handle(): int
    {
        $tables = [
            Translation::modelClass(),
            (string) config('translations.states.model', TranslationState::class),
        ];

        foreach ($tables as $class) {
            /** @var Model $rows */
            $rows = new $class;

            if (! $rows->getConnection()->getSchemaBuilder()->hasTable($rows->getTable())) {
                continue;
            }

            foreach ($rows->newQuery()->toBase()->distinct()->orderBy('translatable_type')->pluck('translatable_type') as $type) {
                $this->prune($rows, (string) $type);
            }
        }

        return self::SUCCESS;
    }

    private function prune(Model $rows, string $type): void
    {
        $owner = Relation::getMorphedModel($type) ?? $type;
        $table = $rows->getTable();

        if (! class_exists($owner) || ! is_a($owner, Model::class, true)) {
            $this->components->warn("[{$table}] Skipped [{$type}]: no model class. Delete its rows yourself if the model was removed.");

            return;
        }

        /** @var Model $model */
        $model = new $owner;

        if ($model->getConnection()->getName() !== $rows->getConnection()->getName()) {
            $this->components->warn("[{$table}] Skipped [{$type}]: the model is on another connection.");

            return;
        }

        $connection = $rows->getConnection();
        $grammar = $connection->getQueryGrammar();
        $ownerKey = MorphKeyType::configured()->ownerKeySql($connection, $grammar->wrap($model->getQualifiedKeyName()));

        $orphans = $rows->newQuery()->toBase()
            ->where('translatable_type', $type)
            ->whereNotExists(fn (Builder $query): Builder => $query
                ->selectRaw('1')
                ->from($model->getTable())
                ->whereRaw($grammar->wrap($rows->qualifyColumn('translatable_id'))." = {$ownerKey}"));

        $count = $this->option('pretend') ? $orphans->count() : $orphans->delete();

        $this->components->info(sprintf('[%s] %s %d orphaned rows of [%s].', $table, $this->option('pretend') ? 'Found' : 'Deleted', $count, $type));
    }
}
