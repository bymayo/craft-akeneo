<?php

namespace bymayo\akeneo\models;

use craft\base\Model;

class Source extends Model
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $type = null;
    public ?int $typeId = null;
    public string $orphanedEntryAction = 'doNothing';
    public ?string $entryIdentifier = null;
    public ?string $akeneoLocale = null;
    public ?int $siteId = null;
    public ?string $filters = null;
    public bool $excludeEmptyRows = false;
    public ?string $lastSyncedAt = null;
    public ?string $uid = null;
    public ?string $dateCreated = null;
    public ?string $dateUpdated = null;

    public function defineRules(): array
    {
        return [
            [['name', 'type', 'typeId'], 'required'],
            [['entryIdentifier', 'akeneoLocale'], 'required', 'when' => fn($model) => $model->id !== null],
            ['type', 'in', 'range' => ['section', 'commerceProductType']],
            ['orphanedEntryAction', 'in', 'range' => ['doNothing', 'disable', 'delete']],
            ['excludeEmptyRows', 'boolean'],
        ];
    }
}
