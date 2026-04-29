<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260429_100000_add_element_id_to_sync_logs extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%akeneo_sync_logs}}', 'elementId')) {
            $this->addColumn('{{%akeneo_sync_logs}}', 'elementId', $this->integer()->null()->after('title'));
            $this->createIndex(null, '{{%akeneo_sync_logs}}', ['elementId']);
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%akeneo_sync_logs}}', 'elementId')) {
            $this->dropColumn('{{%akeneo_sync_logs}}', 'elementId');
        }

        return true;
    }
}
