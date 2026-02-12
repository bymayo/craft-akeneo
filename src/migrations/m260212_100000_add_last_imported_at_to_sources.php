<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260212_100000_add_last_imported_at_to_sources extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%akeneo_sources}}', 'lastImportedAt')) {
            $this->addColumn(
                '{{%akeneo_sources}}',
                'lastImportedAt',
                $this->dateTime()->null()->after('orphanedEntryAction')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%akeneo_sources}}', 'lastImportedAt')) {
            $this->dropColumn('{{%akeneo_sources}}', 'lastImportedAt');
        }

        return true;
    }
}
