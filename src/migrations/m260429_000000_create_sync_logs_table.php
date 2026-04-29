<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260429_000000_create_sync_logs_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%akeneo_sync_logs}}')) {
            return true;
        }

        $this->createTable('{{%akeneo_sync_logs}}', [
            'id' => $this->primaryKey(),
            'sourceId' => $this->integer()->notNull(),
            'sku' => $this->string()->null(),
            'title' => $this->string()->null(),
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

        return true;
    }
}
