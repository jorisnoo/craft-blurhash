<?php

namespace Noo\CraftBlurhash\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%blurhash}}', [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'blurhash' => $this->string()->null(),
            'hasTransparency' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%blurhash}}', 'assetId', true);

        $this->addForeignKey(
            null,
            '{{%blurhash}}',
            'assetId',
            '{{%elements}}',
            'id',
            'CASCADE',
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%blurhash}}');

        return true;
    }
}
