<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260701_000000_add_exclude_empty_rows_to_sources extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%akeneo_sources}}', 'excludeEmptyRows')) {
            $this->addColumn('{{%akeneo_sources}}', 'excludeEmptyRows', $this->boolean()->notNull()->defaultValue(false)->after('filters'));
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%akeneo_sources}}', 'excludeEmptyRows');
        return true;
    }
}
