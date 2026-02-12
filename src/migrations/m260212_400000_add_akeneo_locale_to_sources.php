<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260212_400000_add_akeneo_locale_to_sources extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn('{{%akeneo_sources}}', 'akeneoLocale', $this->string()->null()->after('entryIdentifier'));
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%akeneo_sources}}', 'akeneoLocale');
        return true;
    }
}
