<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260210_000000_remove_sort_order_from_sources extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->columnExists('{{%akeneo_sources}}', 'sortOrder')) {
            $this->dropColumn('{{%akeneo_sources}}', 'sortOrder');
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->columnExists('{{%akeneo_sources}}', 'sortOrder')) {
            $this->addColumn('{{%akeneo_sources}}', 'sortOrder', $this->integer()->defaultValue(0)->after('typeId'));
        }

        return true;
    }
}
