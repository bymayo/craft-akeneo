<?php

namespace bymayo\akeneo\records;

use craft\db\ActiveRecord;

class FieldMappingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%akeneo_source_field_mappings}}';
    }
}
