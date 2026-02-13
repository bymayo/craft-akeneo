<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%akeneo_sources}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'type' => $this->string()->notNull(),
            'typeId' => $this->integer()->notNull(),
            'orphanedEntryAction' => $this->string()->notNull()->defaultValue('doNothing'),
            'entryIdentifier' => $this->string()->null(),
            'akeneoLocale' => $this->string()->null(),
            'siteId' => $this->integer()->null(),
            'filters' => $this->text()->null(),
            'lastSyncedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%akeneo_sources}}', ['type', 'typeId']);

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
        $this->dropTableIfExists('{{%akeneo_sources}}');

        return true;
    }
}
