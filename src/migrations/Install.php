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
            'akeneoAttribute' => $this->text()->notNull(),
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

        $this->createTable('{{%akeneo_sync_logs}}', [
            'id' => $this->primaryKey(),
            'sourceId' => $this->integer()->notNull(),
            'sku' => $this->string()->null(),
            'title' => $this->string()->null(),
            'elementId' => $this->integer()->null(),
            'status' => $this->string(16)->notNull(),
            'message' => $this->text()->null(),
            'isTest' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%akeneo_sync_logs}}', ['sourceId']);
        $this->createIndex(null, '{{%akeneo_sync_logs}}', ['sourceId', 'status']);
        $this->createIndex(null, '{{%akeneo_sync_logs}}', ['dateCreated']);
        $this->createIndex(null, '{{%akeneo_sync_logs}}', ['elementId']);

        $this->addForeignKey(
            null,
            '{{%akeneo_sync_logs}}',
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
        $this->dropTableIfExists('{{%akeneo_sync_logs}}');
        $this->dropTableIfExists('{{%akeneo_source_field_mappings}}');
        $this->dropTableIfExists('{{%akeneo_sources}}');

        return true;
    }
}
