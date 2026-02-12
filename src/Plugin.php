<?php

namespace bymayo\akeneo;

use Craft;
use craft\helpers\FileHelper;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Dashboard;
use craft\utilities\ClearCaches;
use craft\web\UrlManager;
use bymayo\akeneo\models\Settings;
use bymayo\akeneo\services\Attributes;
use bymayo\akeneo\services\Sources;
use bymayo\akeneo\services\Sync;
use bymayo\akeneo\widgets\SyncWidget;
use craft\base\Plugin as BasePlugin;
use yii\base\Event;
use yii\web\Response;

/**
 * Akeneo plugin
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property Attributes $attributes
 * @property Sources $sources
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.8.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function log($message)
    {
        $file = Craft::getAlias('@storage/logs/akeneo.log');
        $log = date('Y-m-d H:i:s'). ' ' . $message . "\n";
        FileHelper::writeToFile($file, $log, ['append' => true]);
    }

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'attributes' => Attributes::class,
            'sync' => Sync::class,
            'sources' => Sources::class,
        ]);

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            // ...
        });

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function (Event $event) {
                $event->types[] = SyncWidget::class;
            }
        );

        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                $event->options[] = [
                    'key' => 'akeneo-attributes',
                    'label' => 'Akeneo attributes',
                    'action' => function () {
                        Craft::$app->getCache()->delete('akeneo_attributes');
                        Craft::$app->getCache()->delete('akeneo_locales');
                    },
                ];
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['url'] = 'akeneo/sources';

        return $item;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): Response
    {
        return Craft::$app->getResponse()->redirect('akeneo/settings');
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('akeneo/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['akeneo'] = 'akeneo/sources/index';
                $event->rules['akeneo/settings'] = 'akeneo/sources/settings';
                $event->rules['akeneo/sources'] = 'akeneo/sources/index';
                $event->rules['akeneo/sources/new'] = 'akeneo/sources/edit';
                $event->rules['akeneo/sources/<sourceId:\d+>'] = 'akeneo/sources/edit';
                $event->rules['akeneo/sources/<sourceId:\d+>/field-mapping'] = 'akeneo/sources/field-mapping';
                $event->rules['akeneo/sources/<sourceId:\d+>/console-commands'] = 'akeneo/sources/console-commands';
            }
        );
    }
}
