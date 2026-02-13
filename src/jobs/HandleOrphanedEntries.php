<?php

namespace bymayo\akeneo\jobs;

use bymayo\akeneo\Plugin;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

class HandleOrphanedEntries extends BaseJob
{
    public int $sourceId;
    public string $syncStartedAt;

    public function execute($queue): void
    {
        $source = Plugin::getInstance()->sources->getSourceById($this->sourceId);

        if (!$source) {
            Plugin::log("Source with ID {$this->sourceId} not found in orphaned entries job");
            return;
        }

        if ($source->orphanedEntryAction === 'doNothing') {
            return;
        }

        $siteId = $source->siteId ?: Craft::$app->getSites()->getPrimarySite()->id;

        // Find elements in this source that were NOT updated during the sync
        $orphanedElements = [];

        if ($source->type === 'section') {
            $orphanedElements = Entry::find()
                ->sectionId($source->typeId)
                ->siteId($siteId)
                ->status(['live', 'pending', 'expired', 'disabled'])
                ->dateUpdated('< ' . $this->syncStartedAt)
                ->limit(null)
                ->all();
        } elseif ($source->type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

            if ($commercePlugin) {
                $orphanedElements = \craft\commerce\elements\Product::find()
                    ->typeId($source->typeId)
                    ->siteId($siteId)
                    ->status(null)
                    ->dateUpdated('< ' . $this->syncStartedAt)
                    ->limit(null)
                    ->all();
            } else {
                Plugin::log("Commerce plugin is not installed. Cannot handle orphaned products for source '{$source->name}'.");
                return;
            }
        }

        if (empty($orphanedElements)) {
            Plugin::log("No orphaned elements found for source '{$source->name}'");
            return;
        }

        $count = count($orphanedElements);
        Plugin::log("Found {$count} orphaned elements for source '{$source->name}', action: {$source->orphanedEntryAction}");

        foreach ($orphanedElements as $i => $element) {
            $this->setProgress($queue, $i / $count);

            if ($source->orphanedEntryAction === 'disable') {
                $element->enabled = false;

                if (!Craft::$app->elements->saveElement($element)) {
                    Plugin::log("Failed to disable orphaned element ID {$element->id}");
                }
            } elseif ($source->orphanedEntryAction === 'delete') {
                if (!Craft::$app->elements->deleteElement($element)) {
                    Plugin::log("Failed to delete orphaned element ID {$element->id}");
                }
            }
        }

        Plugin::log("Processed {$count} orphaned elements for source '{$source->name}'");
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('akeneo', 'Handling orphaned entries');
    }
}
