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

        // Collect only product identifiers here — the full product payload is
        // fetched per-item in SyncProducts. Carrying the entire dataset through
        // the queue spikes memory and makes Craft's batched job abort each batch
        // after a single item (see SyncProducts).
        $identifiers = [];
        $pageCount = 0;

        do {
            foreach ($currentPage->getItems() as $product) {
                $identifier = $product['identifier'] ?? $product['code'] ?? null;

                if ($identifier === null) {
                    continue;
                }

                $identifiers[] = $identifier;

                if ($this->limit !== null && count($identifiers) >= $this->limit) {
                    break 2;
                }
            }

            $currentPage = $currentPage->getNextPage();
            $pageCount++;

            if ($pageCount >= $settings->syncMaxPages) {
                break;
            }
        } while ($currentPage !== null);

        if (empty($identifiers)) {
            Plugin::log("No products found for source '{$source->name}'");
            return;
        }

        Plugin::log("Fetched {$source->name} - Total Products: " . count($identifiers));

        Plugin::pushJob(new SyncProducts([
            'sourceId' => $source->id,
            'syncImages' => $this->syncImages,
            'identifiers' => $identifiers,
            'syncStartedAt' => $syncStartedAt,
            'isTest' => $this->isTest,
        ]));
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('akeneo', 'Fetching products from Akeneo');
    }
}
