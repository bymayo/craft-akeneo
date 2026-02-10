<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260210_100000_add_field_mappings_table extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%akeneo_source_field_mappings}}', [
            'id' => $this->primaryKey(),
            'sourceId' => $this->integer()->notNull(),
            'craftFieldHandle' => $this->string()->notNull(),
            'akeneoAttribute' => $this->string()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%akeneo_source_field_mappings}}', ['sourceId']);
        $this->createIndex(null, '{{%akeneo_source_field_mappings}}', ['sourceId', 'craftFieldHandle'], true);

        $this->addForeignKey(
            null,
            '{{%akeneo_source_field_mappings}}',
            'sourceId',
            '{{%akeneo_sources}}',
            'id',
            'CASCADE',
            'CASCADE'
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%akeneo_source_field_mappings}}');

        return true;
    }
}
