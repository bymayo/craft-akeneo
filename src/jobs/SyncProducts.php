<?php

namespace bymayo\akeneo\jobs;

use bymayo\akeneo\Plugin;

use Craft;
use craft\queue\BaseJob;

/**
 * Sync Products queue job
 */
class SyncProducts extends BaseJob
{

    public $products;
    public $batch;
    public $syncImages;
    public int $sourceId;

    function execute($queue): void
    {
        $totalProducts = count($this->products);
        $source = Plugin::getInstance()->sources->getSourceById($this->sourceId);

        if (!$source) {
            Plugin::log("Source with ID {$this->sourceId} not found in queue job");
            return;
        }

        foreach ($this->products as $index => $product) {
            Plugin::getInstance()->sync->createEntryFromMappings($source, $product, $this->syncImages);

            $this->setProgress($queue, ($index + 1) / $totalProducts, Craft::t('app', '{step} out of {total}', [
                'step' => $index + 1,
                'total' => $totalProducts,
            ]));
        }

    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('app', 'Syncing Products from Akeneo (Batch {batch})', [
            'batch' => $this->batch
        ]);
    }
}
