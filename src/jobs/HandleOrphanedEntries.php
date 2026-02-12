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

        // Find entries in this section that were NOT updated during the sync
        $orphanedEntries = Entry::find()
            ->sectionId($source->typeId)
            ->siteId($siteId)
            ->status(['live', 'pending', 'expired', 'disabled'])
            ->dateUpdated('< ' . $this->syncStartedAt)
            ->limit(null)
            ->all();

        if (empty($orphanedEntries)) {
            Plugin::log("No orphaned entries found for source '{$source->name}'");
            return;
        }

        $count = count($orphanedEntries);
        Plugin::log("Found {$count} orphaned entries for source '{$source->name}', action: {$source->orphanedEntryAction}");

        foreach ($orphanedEntries as $i => $entry) {
            $this->setProgress($queue, $i / $count);

            if ($source->orphanedEntryAction === 'disable') {
                $entry->enabled = false;

                if (!Craft::$app->elements->saveElement($entry)) {
                    Plugin::log("Failed to disable orphaned entry ID {$entry->id}");
                }
            } elseif ($source->orphanedEntryAction === 'delete') {
                if (!Craft::$app->elements->deleteElement($entry)) {
                    Plugin::log("Failed to delete orphaned entry ID {$entry->id}");
                }
            }
        }

        Plugin::log("Processed {$count} orphaned entries for source '{$source->name}'");
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('akeneo', 'Handling orphaned entries');
    }
}
