<?php

namespace bymayo\akeneo\models;

use Craft;
use craft\base\Model;

/**
 * Akeneo settings
 */
class Settings extends Model
{
    public ?string $apiUrl = null;
    public ?string $clientId = null;
    public ?string $secretKey = null;
    public ?string $username = null;
    public ?string $password = null;
    public int $attributeCacheDuration = 21600;
    public int $syncPageSize = 75;
    public int $syncMaxPages = 1000;
    public int $testSyncLimit = 10;
    public string $assetFolderName = 'Akeneo';
    public bool $customQueue = false;
    public int $jobPriority = 1024;
    public int $jobTtr = 300;

    public function defineRules(): array
    {
        return [
            [['apiUrl', 'clientId', 'secretKey', 'username', 'password'], 'required'],
            ['attributeCacheDuration', 'integer', 'min' => 0],
            ['syncPageSize', 'integer', 'min' => 1, 'max' => 100],
            ['syncMaxPages', 'integer', 'min' => 1],
            ['testSyncLimit', 'integer', 'min' => 1, 'max' => 100],
            [['assetFolderName'], 'required'],
            [['assetFolderName'], 'string'],
            ['jobPriority', 'integer', 'min' => 1],
            ['jobTtr', 'integer', 'min' => 60],
        ];
    }
}
