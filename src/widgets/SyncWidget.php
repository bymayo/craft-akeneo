<?php

namespace bymayo\akeneo\widgets;

use Craft;
use craft\base\Widget;
use yii\helpers\Url;

/**
 * Sync widget type
 */
class SyncWidget extends Widget
{
    public static function displayName(): string
    {
        return Craft::t('app', 'Akeneo Product Sync');
    }

    public function getBodyHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('akeneo/widgets/SyncWidget_body');
    }
}
