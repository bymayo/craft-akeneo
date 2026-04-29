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
        return Craft::t('akeneo', 'Akeneo');
    }

    public static function isSelectable(): bool
    {
        return (
            parent::isSelectable() &&
            Craft::$app->getUser()->checkPermission('akeneo-viewWidgets')
        );
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@bymayo/akeneo/icon-mask.svg');
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
        $settings = Plugin::getInstance()->getSettings();
        $settingsMissing = false;

        foreach (['apiUrl', 'clientId', 'secretKey', 'username', 'password'] as $key) {
            if (empty(Craft::parseEnv($settings->{$key}))) {
                $settingsMissing = true;
                break;
            }
        }

        if ($this->sourceId) {
            $source = Plugin::getInstance()->sources->getSourceById($this->sourceId);
            $sources = $source ? [$source] : [];
        } else {
            $sources = Plugin::getInstance()->sources->getAllSources();
        }

        return Craft::$app->view->renderTemplate('akeneo/widgets/SyncWidget_body', [
            'sources' => $sources,
            'settingsMissing' => $settingsMissing,
            'settingsUrl' => \craft\helpers\UrlHelper::cpUrl('akeneo/settings'),
        ]);
    }
}
