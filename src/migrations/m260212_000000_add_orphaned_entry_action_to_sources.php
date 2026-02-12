<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260212_000000_add_orphaned_entry_action_to_sources extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%akeneo_sources}}', 'orphanedEntryAction')) {
            $this->addColumn(
                '{{%akeneo_sources}}',
                'orphanedEntryAction',
                $this->string()->notNull()->defaultValue('doNothing')->after('typeId')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%akeneo_sources}}', 'orphanedEntryAction')) {
            $this->dropColumn('{{%akeneo_sources}}', 'orphanedEntryAction');
        }

        return true;
    }
}
