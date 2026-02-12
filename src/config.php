<?php

/**
 * Akeneo plugin config
 *
 * Copy this file to config/akeneo.php in your Craft project
 * to override plugin settings. Values set here take precedence
 * over CP settings.
 *
 * Supports environment variables via App::env() or getenv().
 */

use craft\helpers\App;

return [
    'apiUrl' => App::env('AKENEO_API_URL'),
    'clientId' => App::env('AKENEO_CLIENT_ID'),
    'secretKey' => App::env('AKENEO_SECRET_KEY'),
    'username' => App::env('AKENEO_USERNAME'),
    'password' => App::env('AKENEO_PASSWORD'),
    'attributeCacheDuration' => 21600,
    'syncPageSize' => 75,
    'syncMaxPages' => 1000,
    'assetVolumeHandle' => 'images',
    'assetFolderName' => 'Akeneo',
];
