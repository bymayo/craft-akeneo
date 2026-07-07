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

    public ?int $limit = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        $options[] = 'source';

        if ($actionID === 'test') {
            $options[] = 'limit';
        }

        return $options;
    }

    /**
     * Sync product data and assets
     */
    public function actionAll(): int
    {
        return $this->runSync(true);
    }

    /**
     * Sync product data only
     */
    public function actionDataOnly(): int
    {
        return $this->runSync(false);
    }

    /**
     * Sync product assets only
     */
    public function actionAssetsOnly(): int
    {
        return $this->runSync(true);
    }

    /**
     * @deprecated in 1.0.18. Use [[actionAssetsOnly()]] (akeneo/sync/assets-only) instead.
     */
    public function actionImagesOnly(): int
    {
        return $this->actionAssetsOnly();
    }

    /**
     * Run a capped Test Sync without triggering orphan handling.
     * Defaults to the Test Sync Limit setting; override with --limit.
     */
    public function actionTest(): int
    {
        $limit = $this->limit ?? Plugin::getInstance()->getSettings()->testSyncLimit;

        if ($limit < 1) {
            $this->stderr("--limit must be 1 or greater.\n");
            return ExitCode::USAGE;
        }

        return $this->runSync(true, $limit, true);
    }

    private function runSync(bool $syncImages, ?int $limit = null, bool $isTest = false): int
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

        $label = $isTest ? "Test syncing source (limit {$limit})" : 'Syncing source';
        $this->stdout("{$label}: {$source->name} (ID: {$source->id})\n");

        Plugin::getInstance()->sync->syncBySource($source, $syncImages, $limit, $isTest);
        Plugin::getInstance()->sources->updateLastSyncedAt($source->id);

        return ExitCode::OK;
    }
}
