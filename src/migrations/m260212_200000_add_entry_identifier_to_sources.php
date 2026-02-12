<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260212_200000_add_entry_identifier_to_sources extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn('{{%akeneo_sources}}', 'entryIdentifier', $this->string()->null()->after('orphanedEntryAction'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%akeneo_sources}}', 'entryIdentifier');

        return true;
    }
}
