<?php

namespace bymayo\akeneo\jobs;

use bymayo\akeneo\Plugin;

use Craft;
use craft\queue\BaseJob;

class FetchProducts extends BaseJob
{
    public int $sourceId;
    public bool $syncImages = true;
    public ?int $limit = null;
    public bool $isTest = false;

    public function execute($queue): void
    {
        $syncStartedAt = (new \DateTime())->format('Y-m-d H:i:s');

        $source = Plugin::getInstance()->sources->getSourceById($this->sourceId);

        if (!$source) {
            Plugin::log("Source with ID {$this->sourceId} not found in fetch job");
            return;
        }

        $sync = Plugin::getInstance()->sync;
        $settings = Plugin::getInstance()->getSettings();

        $searchFilters = $sync->buildSearchFilters($source);

        $queryParams = !empty($searchFilters) ? ['search' => $searchFilters] : [];
        $pageSize = $this->limit !== null ? min($this->limit, $settings->syncPageSize) : $settings->syncPageSize;
        $currentPage = $sync->getClient()->getProductApi()->listPerPage($pageSize, true, $queryParams);

        $allProducts = [];
        $pageCount = 0;

        do {
            $allProducts = array_merge($allProducts, $currentPage->getItems());

            if ($this->limit !== null && count($allProducts) >= $this->limit) {
                $allProducts = array_slice($allProducts, 0, $this->limit);
                break;
            }

            $currentPage = $currentPage->getNextPage();
            $pageCount++;

            if ($pageCount >= $settings->syncMaxPages) {
                break;
            }
        } while ($currentPage !== null);

        if (empty($allProducts)) {
            Plugin::log("No products found for source '{$source->name}'");
            return;
        }

        Plugin::log("Fetched {$source->name} - Total Products: " . count($allProducts));

        Plugin::pushJob(new SyncProducts([
            'sourceId' => $source->id,
            'syncImages' => $this->syncImages,
            'products' => $allProducts,
            'syncStartedAt' => $syncStartedAt,
            'isTest' => $this->isTest,
        ]));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('akeneo', 'Fetching products from Akeneo');
    }
}
