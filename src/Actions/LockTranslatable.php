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

        $attributes = $translatable->getAttributes();
        $translationAttributes = [];

        foreach ($translatable::getTranslatableAttributes() as $attribute) {
            if (! array_key_exists($attribute, $locked->getAttributes())) {
                continue;
            }

            $attributes[$attribute] = $locked->getRawOriginal($attribute);
            $translationAttributes[] = $attribute;
        }

        $translatable->setRawAttributes(
            $attributes,
            sync: false,
        );

        $translatable->syncOriginalAttributes(
            $translationAttributes,
        );

        return $translatable;
    }
}
