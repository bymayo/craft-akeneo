<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260212_300000_add_filters_to_sources extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn('{{%akeneo_sources}}', 'filters', $this->text()->null()->after('entryIdentifier'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%akeneo_sources}}', 'filters');

        return true;
    }
}
