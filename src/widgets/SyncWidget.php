<?php

namespace bymayo\akeneo\widgets;

use bymayo\akeneo\Plugin;

use Craft;
use craft\base\Widget;

class SyncWidget extends Widget
{
    public ?int $sourceId = null;

    public static function displayName(): string
    {
        return Craft::t('akeneo', 'Akeneo Product Sync');
    }

    public static function icon(): ?string
    {
        return 'arrow-rotate-right';
    }

    public function getSettingsHtml(): ?string
    {
        $allSources = Plugin::getInstance()->sources->getAllSources();

        $options = [
            ['label' => 'All Sources', 'value' => ''],
        ];

        foreach ($allSources as $source) {
            $options[] = [
                'label' => $source->name,
                'value' => $source->id,
            ];
        }

        return Craft::$app->view->renderTemplate('akeneo/widgets/SyncWidget_settings', [
            'widget' => $this,
            'sourceOptions' => $options,
        ]);
    }

    public function getBodyHtml(): ?string
    {
        if ($this->sourceId) {
            $source = Plugin::getInstance()->sources->getSourceById($this->sourceId);
            $sources = $source ? [$source] : [];
        } else {
            $sources = Plugin::getInstance()->sources->getAllSources();
        }

        return Craft::$app->view->renderTemplate('akeneo/widgets/SyncWidget_body', [
            'sources' => $sources,
        ]);
    }
}
