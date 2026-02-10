<?php

namespace bymayo\akeneo\controllers;

use bymayo\akeneo\Plugin;

use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Base controller
 */
class SyncController extends Controller
{
    public $defaultAction = 'getProducts';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * akeneo/base action
     */
    public function actionGetProducts(): Response
    {
        $products = Plugin::getInstance()->sync->getProducts(true);
        return $this->redirect(Craft::$app->request->referrer);
    }

    public function actionGetProductsDataOnly(): Response
    {
        $products = Plugin::getInstance()->sync->getProducts(false);
        return $this->redirect(Craft::$app->request->referrer);
    }

}
