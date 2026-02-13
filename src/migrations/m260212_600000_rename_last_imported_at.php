<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260212_600000_rename_last_imported_at extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%akeneo_sources}}', 'lastImportedAt')) {
            $this->renameColumn('{{%akeneo_sources}}', 'lastImportedAt', 'lastSyncedAt');
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%akeneo_sources}}', 'lastSyncedAt')) {
            $this->renameColumn('{{%akeneo_sources}}', 'lastSyncedAt', 'lastImportedAt');
        }

        return true;
    }
}
