<?php

namespace bymayo\akeneo\jobs;

use bymayo\akeneo\Plugin;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;

class SyncProducts extends BaseBatchedJob
{
    public int $sourceId;
    public bool $syncImages = true;
    public array $identifiers = [];
    public int $batchSize = 50;
    public string $syncStartedAt = '';
    public bool $isTest = false;

    protected function loadData(): Batchable
    {
        return new ArrayBatchable($this->identifiers);
    }

    protected function processItem(mixed $item): void
    {
        $source = Plugin::getInstance()->sources->getSourceById($this->sourceId);

        if (!$source) {
            Plugin::log("Source with ID {$this->sourceId} not found in queue job");
            return;
        }

        // Fetch the full product payload on demand. Keeping only identifiers in
        // the job payload keeps memory flat so batches process their full size
        // instead of aborting after one item.
        try {
            $product = Plugin::getInstance()->sync->getClient()->getProductApi()->get($item);
        } catch (\Throwable $e) {
            $message = "Failed to fetch product '{$item}' from Akeneo: " . $e->getMessage();
            Plugin::log($message);
            Plugin::getInstance()->sync->recordSyncLog($source->id, $item, null, 'fail', $message, $this->isTest);
            return;
        }

        Plugin::getInstance()->sync->createEntryFromMappings($source, $product, $this->syncImages, $this->isTest);
    }

    protected function after(): void
    {
        if ($this->isTest) {
            return;
        }

        $source = Plugin::getInstance()->sources->getSourceById($this->sourceId);

        if (!$source || $source->orphanedEntryAction === 'doNothing') {
            return;
        }

        if (empty($this->syncStartedAt)) {
            return;
        }

        Plugin::pushJob(new HandleOrphanedEntries([
            'sourceId' => $this->sourceId,
            'syncStartedAt' => $this->syncStartedAt,
        ]));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('akeneo', 'Syncing {count} products from Akeneo', [
            'count' => count($this->identifiers),
        ]);
    }
}
