<?php

namespace Noo\CraftBlurhash\models;

use craft\base\Model;

class Settings extends Model
{
    public bool $computeOnDemand = true;

    protected function defineRules(): array
    {
        return [
            ['computeOnDemand', 'boolean'],
        ];
    }
}
