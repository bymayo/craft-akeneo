<?php

namespace bymayo\akeneo\jobs;

use bymayo\akeneo\Plugin;

use Craft;
use craft\queue\BaseJob;
use craft\elements\Entry;

/**
 * Sync Products queue job
 */
class SyncProducts extends BaseJob
{

    public $products;
    public $categories;
    public $batch;
    public $syncImages;

    function execute($queue): void
    {

        $totalProducts = count($this->products);

        foreach ($this->products as $index => $product) {

            Plugin::getInstance()->sync->createProductEntry($product, $this->categories, $this->syncImages);

            $progress = ($index + 1) / $totalProducts;

            $this->setProgress($queue, $progress, Craft::t('app', '{step} out of {total}', [
                'step' => $index + 1,
                'total' => $totalProducts
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
