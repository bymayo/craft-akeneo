<img src="https://github.com/bymayo/craft-akeneo/blob/craft-5/resources/icon.png" width="60">

# Akeneo for Craft CMS 5

Akeneo is a Craft CMS plugin that syncs products and data from [Akeneo PIM](https://www.akeneo.com/) into Craft CMS entries.

## Features

- **Source Management** - Create multiple sources to sync different Akeneo product sets into different Craft sections or Commerce product types
- **Field Mapping** - Map Akeneo attributes to Craft fields with support for plain text, numbers, dropdowns, dates, table fields, matrix fields and asset fields
- **Asset Syncing** - Download and sync Akeneo asset collections into Craft asset fields, respecting each field's volume configuration
- **Multi-Asset Support** - Asset fields that allow multiple assets can be mapped to multiple Akeneo asset collections
- **Static Values** - Set static values on mapped fields instead of pulling from Akeneo
- **Product Filters** - Filter which Akeneo products are imported using attribute-based search filters (equals, contains, in, between, empty, etc.)
- **Per-Source Locale** - Choose which Akeneo locale to pull attribute values from per source (e.g. `en_GB`, `en_US`)
- **Multi-Site Support** - Import entries into a specific Craft site per source
- **Orphaned Entry Handling** - Automatically disable or delete Craft entries that no longer exist in Akeneo after a sync
- **Queue Based Syncing** - All syncing runs via Craft's queue system so the CP stays responsive
- **Batched Jobs** - Large syncs are automatically split into batches using Craft's `BaseBatchedJob`
- **Dashboard Widget** - Trigger syncs directly from the dashboard with options for all data, data only, or images only
- **Console Commands** - Run syncs from the terminal or cron jobs
- **Connection Test** - Verify your Akeneo API connection from the settings page

## Install

- Install with Composer via `composer require bymayo/akeneo` from your project directory
- Enable / Install the plugin in the Craft Control Panel under `Settings > Plugins`
- Configure the API settings under `Settings > Plugins > Akeneo`

## Requirements

- Craft CMS 5.x
- PHP 8.2
- MySQL (No PostgreSQL support)

## Setup

### 1. Configure API Settings

Navigate to `Settings > Plugins > Akeneo` and enter your Akeneo PIM API credentials:

| Setting | Description |
|---|---|
| API URL | The base URL of your Akeneo PIM instance |
| Client ID | The Akeneo API client ID |
| Secret Key | The Akeneo API secret key |
| Username | The Akeneo API username |
| Password | The Akeneo API password |

All credential fields support environment variables.

A connection status indicator at the top of the settings page will confirm if your credentials are valid.

### 2. Create a Source

Navigate to the Akeneo section in the CP sidebar and click `New Source`:

1. Give the source a **Name**
2. Select the **Type** (Craft Section or Commerce Product Type)
3. Set the **Orphaned Entry Action** (Do Nothing, Disable, or Delete)
4. Choose an **Entry Identifier** field for matching existing entries during sync (e.g. SKU)
5. Select the **Akeneo Locale** to pull attribute values from
6. Optionally select a **Site** to import entries into
7. Optionally add **Product Filters** to limit which products are synced

### 3. Map Fields

After saving the source, navigate to the **Field Mapping** tab. Map each Craft field to an Akeneo attribute, a static value, or leave unmapped.

Supported field types:

- Plain Text
- Number
- Email
- URL
- Dropdown
- Radio Buttons
- Lightswitch
- Color
- Date
- Money
- Table
- Matrix (with nested field mapping)
- Assets (single and multi-select)

### 4. Run a Sync

Syncs can be triggered from:

- **Source list** - Sync All, Sync Data, or Sync Images buttons per source
- **Dashboard widget** - Add the Akeneo Product Sync widget
- **Console commands** - See below

## Console Commands

Each source has a **Console Commands** tab with copy-paste ready commands:

```
php craft akeneo/sync/all --source=1
php craft akeneo/sync/data-only --source=1
php craft akeneo/sync/images-only --source=1
```

## Config File

You can override plugin settings by creating a `config/akeneo.php` file in your Craft project:

```php
<?php

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
    'assetFolderName' => 'Akeneo',
];
```

| Setting | Default | Description |
|---|---|---|
| `apiUrl` | `null` | The base URL of your Akeneo PIM instance |
| `clientId` | `null` | The Akeneo API client ID |
| `secretKey` | `null` | The Akeneo API secret key |
| `username` | `null` | The Akeneo API username |
| `password` | `null` | The Akeneo API password |
| `attributeCacheDuration` | `21600` | How long (in seconds) to cache Akeneo attributes. Set to `0` to disable |
| `syncPageSize` | `75` | Number of products to fetch per API request during sync |
| `syncMaxPages` | `1000` | Maximum number of pages to fetch during a sync |
| `assetFolderName` | `Akeneo` | The folder name within the volume where Akeneo images are stored |

## Support

If you have any issues (Surely not!) then I'll aim to reply to these as soon as possible. If it's a site-breaking-oh-no-what-has-happened moment, then hit me up on the Craft CMS Discord - @bymayo
