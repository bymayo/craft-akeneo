<?php

namespace bymayo\akeneo\console\controllers;

use bymayo\akeneo\Plugin;

use Craft;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Sync controller
 */
class SyncController extends Controller
{
    public $defaultAction = 'all';

    public ?int $source = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'source';
        return $options;
    }

    /**
     * Sync product data and images
     */
    public function actionAll(): int
    {
        return $this->runSync(true, true);
    }

    /**
     * Sync product data only
     */
    public function actionDataOnly(): int
    {
        return $this->runSync(false, false);
    }

    /**
     * Sync product images only
     */
    public function actionImagesOnly(): int
    {
        return $this->runSync(false, true);
    }

    private function runSync(bool $syncData, bool $syncImages): int
    {
        if ($this->source === null) {
            $this->stderr("The --source flag is required. Usage: php craft akeneo/sync --source=1\n");
            return ExitCode::USAGE;
        }

        $source = Plugin::getInstance()->sources->getSourceById($this->source);

        if (!$source) {
            $this->stderr("Source with ID {$this->source} not found.\n");
            return ExitCode::USAGE;
        }

        $this->stdout("Syncing source: {$source->name} (ID: {$source->id})\n");

        Plugin::getInstance()->sync->syncBySource($source, $syncImages);
        Plugin::getInstance()->sources->updateLastSyncedAt($source->id);

        return ExitCode::OK;
    }
}
