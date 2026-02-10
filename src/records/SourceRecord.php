<?php

namespace bymayo\akeneo\records;

use craft\db\ActiveRecord;

class SourceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%akeneo_sources}}';
    }
}
