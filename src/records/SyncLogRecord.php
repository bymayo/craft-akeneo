<?php

namespace bymayo\akeneo\records;

use craft\db\ActiveRecord;

class SyncLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%akeneo_sync_logs}}';
    }
}
