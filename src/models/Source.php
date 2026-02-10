<?php

namespace bymayo\akeneo\models;

use craft\base\Model;

class Source extends Model
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $type = null;
    public ?int $typeId = null;
    public ?string $uid = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    public function defineRules(): array
    {
        return [
            [['name', 'type', 'typeId'], 'required'],
            ['type', 'in', 'range' => ['section', 'commerceProductType']],
        ];
    }
}
