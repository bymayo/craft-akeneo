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

    public function defineRules(): array
    {
        return [
            [['apiUrl', 'clientId', 'secretKey', 'username', 'password'], 'required'],
            ['attributeCacheDuration', 'integer', 'min' => 0],
        ];
    }
}
