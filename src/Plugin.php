<?php

namespace bymayo\akeneo;

use Craft;
use craft\helpers\FileHelper;
use craft\services\Dashboard;
use bymayo\akeneo\models\Settings;
use bymayo\akeneo\services\Sync;
use bymayo\akeneo\widgets\SyncWidget;
use craft\base\Plugin as BasePlugin;
use yii\base\Event;

/**
 * Akeneo plugin
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public bool $hasCpSettings = true;

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
            'sync' => Sync::class,
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
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
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

    }
}
