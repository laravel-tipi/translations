<?php

declare(strict_types=1);

namespace Tipi\Translations\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A morphMany() whose translatable_id column is a string, for
 * MorphKeyType::String. Owner keys are bound and compared as strings, so
 * integer keys never meet the string column as numbers: PostgreSQL
 * rejects that comparison and MySQL would skip the index.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 *
 * @internal
 */
final class StringKeyMorphMany extends MorphMany
{
    public function getParentKey(): ?string
    {
        $key = parent::getParentKey();

        return $key === null ? null : (string) $key;
    }

    public function addEagerConstraints(array $models): void
    {
        $this->whereInEager(
            'whereIn',
            $this->foreignKey,
            array_map(strval(...), $this->getKeys($models, $this->localKey)),
            $this->getRelationQuery(),
        );

        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * For has(), whereHas() and withCount().
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        $grammar = $query->getQuery()->getGrammar();
        $owner = MorphKeyType::String->ownerKeySql($query->getConnection(), $grammar->wrap($this->getQualifiedParentKeyName()));

        return $query->select($columns)
            ->whereRaw($grammar->wrap($this->getExistenceCompareKey()).' = '.$owner)
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }
}
