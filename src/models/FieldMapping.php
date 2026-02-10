<?php

namespace bymayo\akeneo\models;

use craft\base\Model;

class FieldMapping extends Model
{
    public ?int $id = null;
    public ?int $sourceId = null;
    public ?string $craftFieldHandle = null;
    public ?string $akeneoAttribute = null;
    public ?string $uid = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    public function defineRules(): array
    {
        return [
            [['sourceId', 'craftFieldHandle', 'akeneoAttribute'], 'required'],
        ];
    }
}
