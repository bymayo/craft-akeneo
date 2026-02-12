<?php

namespace bymayo\akeneo\migrations;

use craft\db\Migration;

class m260212_500000_add_site_id_to_sources extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn('{{%akeneo_sources}}', 'siteId', $this->integer()->null()->after('akeneoLocale'));

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn('{{%akeneo_sources}}', 'siteId');

        return true;
    }
}
