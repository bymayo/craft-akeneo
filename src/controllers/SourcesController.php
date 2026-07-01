<?php

namespace bymayo\akeneo\controllers;

use bymayo\akeneo\Plugin;
use bymayo\akeneo\models\Source;

use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SourcesController extends Controller
{
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('akeneo-manageSources');

        return true;
    }

    public function actionSettings(): Response
    {
        return $this->renderTemplate('akeneo/settings/_edit', [
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    public function actionIndex(): Response
    {
        $sources = Plugin::getInstance()->sources->getAllSources();

        $tableData = [];

        foreach ($sources as $source) {
            $typeLabel = Plugin::getInstance()->sources->getTypeLabelById($source->type, $source->typeId);
            $typePrefix = $source->type === 'section' ? 'Section' : 'Commerce Product';

            $tableData[] = [
                'id' => $source->id,
                'title' => $source->name,
                'url' => 'akeneo/sources/' . $source->id,
                'contentType' => $typePrefix,
                'type' => $typeLabel,
                'lastSyncedAt' => $source->lastSyncedAt,
                'estimatedProducts' => Plugin::getInstance()->sources->getEstimatedProductCount($source),
                'hasFailures' => Plugin::getInstance()->sync->sourceHasFailures($source->id),
            ];
        }

        return $this->renderTemplate('akeneo/sources/index', [
            'sources' => $tableData,
        ]);
    }

    public function actionEdit(?int $sourceId = null): Response
    {
        if ($sourceId) {
            $source = Plugin::getInstance()->sources->getSourceById($sourceId);

            if (!$source) {
                throw new NotFoundHttpException('Source not found');
            }
        } else {
            $source = new Source();
        }

        return $this->renderTemplate('akeneo/sources/_edit', $this->_editTemplateParams($source));
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $sourceId = $request->getBodyParam('sourceId');
        $isNew = empty($sourceId);

        if ($sourceId) {
            $source = Plugin::getInstance()->sources->getSourceById($sourceId);

            if (!$source) {
                throw new NotFoundHttpException('Source not found');
            }
        } else {
            $source = new Source();
        }

        $source->name = $request->getBodyParam('name');

        // Parse composite type value (e.g. "section:5" or "commerceProductType:3")
        $typeValue = $request->getBodyParam('type');

        if ($typeValue && str_contains($typeValue, ':')) {
            [$source->type, $typeId] = explode(':', $typeValue, 2);
            $source->typeId = (int) $typeId;
        }

        $source->orphanedEntryAction = $request->getBodyParam('orphanedEntryAction', 'doNothing');
        $source->entryIdentifier = $request->getBodyParam('entryIdentifier') ?: null;
        $source->akeneoLocale = $request->getBodyParam('akeneoLocale') ?: null;
        $source->excludeEmptyRows = (bool) $request->getBodyParam('excludeEmptyRows');

        $siteIdParam = $request->getBodyParam('siteId');
        $source->siteId = $siteIdParam ? (int) $siteIdParam : null;

        // Build filters JSON from repeatable rows
        $rawFilters = $request->getBodyParam('filters', []);
        $filters = [];

        foreach ($rawFilters as $filter) {
            $attribute = trim($filter['attribute'] ?? '');
            $operator = trim($filter['operator'] ?? '');

            if ($attribute !== '' && $operator !== '') {
                $filters[] = [
                    'attribute' => $attribute,
                    'operator' => $operator,
                    'value' => trim($filter['value'] ?? ''),
                ];
            }
        }

        $source->filters = !empty($filters) ? json_encode($filters) : null;

        if (!Plugin::getInstance()->sources->saveSource($source)) {
            Craft::$app->getSession()->setError('Couldn\'t save source: ' . implode(', ', $source->getErrorSummary(true)));

            Craft::$app->getUrlManager()->setRouteParams(
                $this->_editTemplateParams($source)
            );

            return null;
        }

        Craft::$app->getSession()->setNotice('Source saved.');

        if ($isNew) {
            return $this->redirect('akeneo/sources/' . $source->id . '/field-mapping');
        }

        return $this->redirectToPostedUrl($source);
    }

    public function actionExport(int $sourceId): Response
    {
        $source = Plugin::getInstance()->sources->getSourceById($sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Source not found');
        }

        $data = Plugin::getInstance()->sources->getSourceExportData($source);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $slug = $source->name ? \craft\helpers\StringHelper::toKebabCase($source->name) : (string) $source->id;
        $filename = 'akeneo-source-' . ($slug ?: $source->id) . '.json';

        return Craft::$app->getResponse()->sendContentAsFile($json, $filename, [
            'mimeType' => 'application/json',
        ]);
    }

    public function actionImport(): Response
    {
        if (!Craft::$app->getRequest()->getIsPost()) {
            return $this->redirect('akeneo/sources');
        }

        $this->requirePostRequest();

        $uploadedFile = \yii\web\UploadedFile::getInstanceByName('file');

        if (!$uploadedFile) {
            Craft::$app->getSession()->setError('Please choose a file to import.');

            return $this->redirect('akeneo/sources/import');
        }

        $data = json_decode((string) file_get_contents($uploadedFile->tempName), true);

        if (!is_array($data)) {
            Craft::$app->getSession()->setError('The import file isn\'t valid JSON.');

            return $this->redirect('akeneo/sources/import');
        }

        if (isset($data['source']['name'])) {
            $data['source']['name'] .= ' (imported)';
        }

        try {
            $source = Plugin::getInstance()->sources->createSourceFromImport($data);
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError($e->getMessage());

            return $this->redirect('akeneo/sources/import');
        }

        Craft::$app->getSession()->setNotice('Source imported.');

        return $this->redirect('akeneo/sources');
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();

        $sourceId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sourceId');
        $source = Plugin::getInstance()->sources->getSourceById($sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Source not found');
        }

        // Duplicating is an export + import on the same environment.
        $data = Plugin::getInstance()->sources->getSourceExportData($source);
        $data['source']['name'] = $source->name . ' (copy)';

        try {
            Plugin::getInstance()->sources->createSourceFromImport($data);
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        Craft::$app->getSession()->setNotice('Source duplicated.');

        return $this->asJson(['success' => true]);
    }

    public function actionConsoleCommands(int $sourceId): Response
    {
        $source = Plugin::getInstance()->sources->getSourceById($sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Source not found');
        }

        return $this->renderTemplate('akeneo/sources/_console-commands', [
            'source' => $source,
            'sourceHasFailures' => Plugin::getInstance()->sync->sourceHasFailures($source->id),
        ]);
    }

    public function actionLog(int $sourceId): Response
    {
        $source = Plugin::getInstance()->sources->getSourceById($sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Source not found');
        }

        $request = Craft::$app->getRequest();
        $status = $request->getQueryParam('status');

        if (!in_array($status, ['success', 'warning', 'fail'], true)) {
            $status = null;
        }

        $page = max(1, (int) $request->getQueryParam('page', 1));
        $perPage = 100;
        $offset = ($page - 1) * $perPage;

        $logs = Plugin::getInstance()->sync->getLogsForSource($sourceId, $status, $perPage, $offset);
        $counts = Plugin::getInstance()->sync->getLogCountsForSource($sourceId);

        return $this->renderTemplate('akeneo/sources/_log', [
            'source' => $source,
            'title' => $source->name,
            'logs' => $logs,
            'counts' => $counts,
            'statusFilter' => $status,
            'page' => $page,
            'perPage' => $perPage,
            'sourceHasFailures' => $counts['fail'] > 0,
        ]);
    }

    public function actionClearLog(): ?Response
    {
        $this->requirePostRequest();

        $sourceId = (int) Craft::$app->getRequest()->getRequiredBodyParam('sourceId');
        $source = Plugin::getInstance()->sources->getSourceById($sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Source not found');
        }

        Plugin::getInstance()->sync->clearLogsForSource($sourceId);

        Craft::$app->getSession()->setNotice('Log cleared.');

        return $this->redirect('akeneo/sources/' . $sourceId . '/log');
    }

    public function actionFieldMapping(int $sourceId): Response
    {
        $source = Plugin::getInstance()->sources->getSourceById($sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Source not found');
        }

        $mappings = Plugin::getInstance()->sources->getMappingsBySourceId($sourceId);
        $craftFields = Plugin::getInstance()->sources->getCraftFieldsForSource($source);

        $akeneoAttributes = [];
        $akeneoError = null;

        try {
            $akeneoAttributes = Plugin::getInstance()->sources->getAkeneoAttributes();
        } catch (\Throwable $e) {
            $akeneoError = $e->getMessage();
        }

        return $this->renderTemplate('akeneo/sources/_field-mapping', [
            'source' => $source,
            'title' => $source->name,
            'mappings' => $mappings,
            'craftFields' => $craftFields,
            'akeneoAttributes' => $akeneoAttributes,
            'akeneoError' => $akeneoError,
            'sourceHasFailures' => Plugin::getInstance()->sync->sourceHasFailures($source->id),
        ]);
    }

    public function actionSaveFieldMapping(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $sourceId = (int) $request->getRequiredBodyParam('sourceId');

        $source = Plugin::getInstance()->sources->getSourceById($sourceId);

        if (!$source) {
            throw new NotFoundHttpException('Source not found');
        }

        $rawMappings = $request->getBodyParam('mappings', []);
        $mappings = [];

        foreach ($rawMappings as $mapping) {
            $handle = $mapping['craftFieldHandle'] ?? '';

            if (empty($handle)) {
                continue;
            }

            // Table field: encode rows as JSON
            if (!empty($mapping['tableRows'])) {
                $rows = [];

                foreach ($mapping['tableRows'] as $row) {
                    $hasData = false;
                    $rowData = [];

                    foreach ($row as $colId => $colData) {
                        $akeneoVal = $colData['akeneo'] ?? '';
                        if ($akeneoVal === '__none__') {
                            $akeneoVal = '';
                        }

                        $rowData[$colId] = [
                            'static' => $colData['static'] ?? '',
                            'akeneo' => $akeneoVal,
                        ];

                        if (!empty($colData['static']) || !empty($akeneoVal)) {
                            $hasData = true;
                        }
                    }

                    if ($hasData) {
                        $rows[] = $rowData;
                    }
                }

                if (!empty($rows)) {
                    $mappings[] = [
                        'craftFieldHandle' => $handle,
                        'akeneoAttribute' => json_encode($rows),
                    ];
                }

                continue;
            }

            // Matrix field: encode entry type row mappings as JSON
            if (!empty($mapping['matrix'])) {
                $matrixData = [];

                foreach ($mapping['matrix'] as $entryTypeHandle => $entryRows) {
                    $entryTypeRows = [];

                    foreach ($entryRows as $rowIdx => $fields) {
                        $rowData = [];

                        foreach ($fields as $fieldHandle => $fieldData) {
                            if (!empty($fieldData['tableRows'])) {
                                $tableRows = [];

                                foreach ($fieldData['tableRows'] as $tableRow) {
                                    $hasData = false;
                                    $tableRowData = [];

                                    foreach ($tableRow as $colId => $colData) {
                                        $akeneoVal = $colData['akeneo'] ?? '';
                                        if ($akeneoVal === '__none__') {
                                            $akeneoVal = '';
                                        }

                                        $tableRowData[$colId] = [
                                            'static' => $colData['static'] ?? '',
                                            'akeneo' => $akeneoVal,
                                        ];

                                        if (!empty($colData['static']) || !empty($akeneoVal)) {
                                            $hasData = true;
                                        }
                                    }

                                    if ($hasData) {
                                        $tableRows[] = $tableRowData;
                                    }
                                }

                                if (!empty($tableRows)) {
                                    $rowData[$fieldHandle] = $tableRows;
                                }
                            } elseif (!empty($fieldData['akeneoMulti'])) {
                                // Multi-asset field inside matrix
                                $assetCodes = array_filter($fieldData['akeneoMulti']);
                                if (!empty($assetCodes)) {
                                    $rowData[$fieldHandle] = array_values($assetCodes);
                                }
                            } else {
                                $akeneo = $fieldData['akeneo'] ?? '';
                                $static = $fieldData['static'] ?? '';

                                if ($akeneo === '__static__' && $static !== '') {
                                    $rowData[$fieldHandle] = 'static:' . $static;
                                } elseif (!empty($akeneo) && $akeneo !== '__static__' && $akeneo !== '__none__') {
                                    $rowData[$fieldHandle] = $akeneo;
                                }
                            }
                        }

                        if (!empty($rowData)) {
                            $entryTypeRows[] = $rowData;
                        }
                    }

                    if (!empty($entryTypeRows)) {
                        $matrixData[$entryTypeHandle] = $entryTypeRows;
                    }
                }

                if (!empty($matrixData)) {
                    $mappings[] = [
                        'craftFieldHandle' => $handle,
                        'akeneoAttribute' => json_encode($matrixData),
                    ];
                }

                continue;
            }

            // Categories field with parent placement: encode rows of
            // { parentId, akeneo: [...codes] } under a discriminated object.
            if (!empty($mapping['catParentMode']) && !empty($mapping['catParent'])) {
                $rows = [];

                foreach ($mapping['catParent'] as $row) {
                    $parentId = $row['parentId'] ?? '';
                    $akeneoCodes = array_values(array_filter(
                        $row['akeneo'] ?? [],
                        static fn($code) => $code !== '' && $code !== '__none__' && $code !== '__static__'
                    ));

                    if (!empty($akeneoCodes)) {
                        $rows[] = [
                            'parentId' => $parentId !== '' ? (int) $parentId : null,
                            'akeneo' => $akeneoCodes,
                        ];
                    }
                }

                if (!empty($rows)) {
                    $mappings[] = [
                        'craftFieldHandle' => $handle,
                        'akeneoAttribute' => json_encode([
                            '__akeneoCategoriesParent' => true,
                            'rows' => $rows,
                        ]),
                    ];
                }

                continue;
            }

            // Multi-asset field
            if (!empty($mapping['akeneoAssetMulti'])) {
                $assetCodes = array_filter($mapping['akeneoAssetMulti']);
                if (!empty($assetCodes)) {
                    $mappings[] = [
                        'craftFieldHandle' => $handle,
                        'akeneoAttribute' => json_encode(array_values($assetCodes)),
                    ];
                }
                continue;
            }

            // Regular field
            $akeneoAttribute = $mapping['akeneoAttribute'] ?? '';
            $staticValue = $mapping['staticValue'] ?? '';

            if ($akeneoAttribute === '__static__' && $staticValue !== '') {
                $mappings[] = [
                    'craftFieldHandle' => $handle,
                    'akeneoAttribute' => 'static:' . $staticValue,
                ];
            } elseif (!empty($akeneoAttribute) && $akeneoAttribute !== '__static__' && $akeneoAttribute !== '__none__') {
                $mappings[] = [
                    'craftFieldHandle' => $handle,
                    'akeneoAttribute' => $akeneoAttribute,
                ];
            }
        }

        Plugin::getInstance()->sources->saveMappings($sourceId, $mappings);

        Craft::$app->getSession()->setNotice('Field mappings saved.');

        return $this->redirectToPostedUrl($source);
    }

    public function actionRefreshAkeneoAttributes(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $sourceId = (int) $request->getRequiredBodyParam('sourceId');

        Craft::$app->getCache()->delete('akeneo_attributes_v2');
        Craft::$app->getCache()->delete('akeneo_locales');

        Craft::$app->getSession()->setNotice('Akeneo attributes cache refreshed.');

        return $this->redirect('akeneo/sources/' . $sourceId);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = Craft::$app->getRequest()->getRequiredBodyParam('id');

        Plugin::getInstance()->sources->deleteSourceById($id);

        return $this->asJson(['success' => true]);
    }

    private function _editTemplateParams(Source $source): array
    {
        $params = [
            'source' => $source,
            'title' => $source->id ? $source->name : 'Create a new source',
            'typeOptions' => Plugin::getInstance()->sources->getTypeOptions(),
            'sourceHasFailures' => $source->id ? Plugin::getInstance()->sync->sourceHasFailures($source->id) : false,
        ];

        if ($source->id && $source->type && $source->typeId) {
            $craftFields = Plugin::getInstance()->sources->getCraftFieldsForSource($source);
            $identifierOptions = [];

            foreach ($craftFields as $field) {
                if (in_array($field['type'], ['field']) && $field['supported'] && !in_array($field['handle'], ['variantTitle', 'sku', 'price'])) {
                    $identifierOptions[] = [
                        'label' => $field['name'],
                        'value' => $field['handle'],
                    ];
                }
            }

            $params['identifierOptions'] = $identifierOptions;
        }

        $siteOptions = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = [
                'label' => $site->getName(),
                'value' => $site->id,
            ];
        }
        $params['siteOptions'] = $siteOptions;

        $settings = Plugin::getInstance()->getSettings();
        $missingSettings = false;

        foreach (['apiUrl', 'clientId', 'secretKey', 'username', 'password'] as $key) {
            if (empty(Craft::parseEnv($settings->{$key}))) {
                $missingSettings = true;
                break;
            }
        }

        if ($missingSettings) {
            $params['localeSettingsMissing'] = true;
            $params['settingsUrl'] = \craft\helpers\UrlHelper::cpUrl('akeneo/settings');
        } else {
            try {
                $locales = Plugin::getInstance()->sources->getAkeneoLocales();
                $localeOptions = [];

                foreach ($locales as $locale) {
                    $localeOptions[] = [
                        'label' => $locale['label'],
                        'value' => $locale['code'],
                    ];
                }

                $params['localeOptions'] = $localeOptions;
            } catch (\Throwable $e) {
                $params['localeError'] = $e->getMessage();
            }
        }

        return $params;
    }
}
