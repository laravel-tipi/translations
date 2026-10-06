<?php

declare(strict_types=1);

namespace Tipi\Translations\Actions;

use Illuminate\Database\Eloquent\Model;
use Tipi\Translations\Contracts\TranslatableModel;

final class LockTranslatable
{
    /**
     * @template T of Model&TranslatableModel
     *
     * @param  T  $translatable
     * @return T
     */
    public function execute(
        Model&TranslatableModel $translatable,
    ): Model&TranslatableModel {
        $locked = $translatable->newQuery()
            ->lockForUpdate()
            ->findOrFail($translatable->getKey());

        $translatable->setRawAttributes(
            $locked->getAttributes(),
            true,
        );

        return $translatable;
    }
}
