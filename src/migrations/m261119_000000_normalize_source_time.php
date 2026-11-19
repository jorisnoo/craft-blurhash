<?php

namespace Noo\CraftBlurhash\migrations;

use craft\db\Migration;
use yii\db\Expression;

class m261119_000000_normalize_source_time extends Migration
{
    public function safeUp(): bool
    {
        // Legacy rows mix local timestamps and UTC backfills. The asset table
        // holds the authoritative UTC mtime. Don't mark a later replacement
        // current if the record predates it; keep serving the old hash meanwhile.
        $this->update('{{%blurhash}}', ['sourceDateModified' => new Expression(
            '(SELECT a.[[dateModified]] FROM {{%assets}} a WHERE a.[[id]] = {{%blurhash}}.[[assetId]] AND a.[[dateModified]] <= {{%blurhash}}.[[dateUpdated]])'
        )], [], [], false);
        return true;
    }

    public function safeDown(): bool
    {
        return false;
    }
}
