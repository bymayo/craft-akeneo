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
    public array $products = [];
    public int $batchSize = 50;
    public string $syncStartedAt = '';

    protected function loadData(): Batchable
    {
        return new ArrayBatchable($this->products);
    }

    protected function processItem(mixed $item): void
    {
        $source = Plugin::getInstance()->sources->getSourceById($this->sourceId);

        if (!$source) {
            Plugin::log("Source with ID {$this->sourceId} not found in queue job");
            return;
        }

        Plugin::getInstance()->sync->createEntryFromMappings($source, $item, $this->syncImages);
    }

    protected function after(): void
    {
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
            'count' => count($this->products),
        ]);
    }
}
