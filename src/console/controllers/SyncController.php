<?php

namespace bymayo\akeneo\console\controllers;

use bymayo\akeneo\Plugin;

use Craft;
use craft\console\Controller;
use yii\console\ExitCode;

/**
 * Sync controller
 */
class SyncController extends Controller
{
    public $defaultAction = 'get-products';

    public function options($actionID): array
    {
        $options = parent::options($actionID);
        switch ($actionID) {
            case 'index':
                // $options[] = '...';
                break;
        }
        return $options;
    }

    /**
     * Sync product data and images
     */
    public function actionGetProducts(): int
    {

        $products = Plugin::getInstance()->sync->getProducts(true);
        return ExitCode::OK;
        
    }

    /**
     * Sync product data only
     */
    public function actionGetProductsDataOnly(): int
    {
        $products = Plugin::getInstance()->sync->getProducts(false);
        return ExitCode::OK;
    }
}
