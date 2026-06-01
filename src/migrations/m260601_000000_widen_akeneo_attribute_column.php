<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260601_000000_widen_akeneo_attribute_column extends Migration
{
    public function safeUp(): bool
    {
        // Matrix/table mappings store large JSON in akeneoAttribute, which
        // overflows the original VARCHAR(255). Widen it to TEXT.
        $this->alterColumn('{{%akeneo_source_field_mappings}}', 'akeneoAttribute', $this->text()->notNull());

        return true;
    }

    public function safeDown(): bool
    {
        $this->alterColumn('{{%akeneo_source_field_mappings}}', 'akeneoAttribute', $this->string()->notNull());

        return true;
    }
}
