<?php

namespace Noo\CraftBlurhash\migrations;

use craft\db\Migration;

class m260515_000000_add_source_date_modified extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%blurhash}}';

        if ($this->db->getTableSchema($table)?->getColumn('sourceDateModified') === null) {
            $this->addColumn($table, 'sourceDateModified', $this->dateTime()->null()->after('hasTransparency'));
        }

        // Backfill so existing rows are treated as up-to-date and don't trigger
        // a regen storm on the next re-index.
        $this->update($table, [
            'sourceDateModified' => new \yii\db\Expression('[[dateUpdated]]'),
        ], ['sourceDateModified' => null]);

        return true;
    }

    public function safeDown(): bool
    {
        $table = '{{%blurhash}}';

        if ($this->db->getTableSchema($table)?->getColumn('sourceDateModified') !== null) {
            $this->dropColumn($table, 'sourceDateModified');
        }

        return true;
    }
}
