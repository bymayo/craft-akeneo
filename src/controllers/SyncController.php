<?php

namespace bymayo\akeneo\controllers;

use bymayo\akeneo\Plugin;

use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\Response;

use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;

class SyncController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();

        try {
            $settings = Plugin::getInstance()->getSettings();

            $clientBuilder = new AkeneoPimClientBuilder(
                Craft::parseEnv($settings->apiUrl)
            );

            $client = $clientBuilder->buildAuthenticatedByPassword(
                Craft::parseEnv($settings->clientId),
                Craft::parseEnv($settings->secretKey),
                Craft::parseEnv($settings->username),
                Craft::parseEnv($settings->password)
            );

            $client->getAttributeApi()->listPerPage(1);

            return $this->asJson([
                'success' => true,
                'message' => 'Connected to Akeneo successfully.',
            ]);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $type = $request->getRequiredBodyParam('type');
        $sourceId = (int) $request->getRequiredBodyParam('sourceId');

        $source = Plugin::getInstance()->sources->getSourceById($sourceId);

        if (!$source) {
            throw new BadRequestHttpException('Invalid source ID');
        }

        $sync = Plugin::getInstance()->sync;

        $syncImages = match ($type) {
            'data' => false,
            'images', 'all' => true,
            default => throw new BadRequestHttpException('Invalid sync type'),
        };

        $sync->syncBySource($source, $syncImages);

        Plugin::getInstance()->sources->updateLastSyncedAt($sourceId);

        return $this->asJson(['success' => true]);
    }
}
