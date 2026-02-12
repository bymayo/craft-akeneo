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
                'lastImportedAt' => $source->lastImportedAt,
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

            $title = $source->name;
        } else {
            $source = new Source();
            $title = 'Create a new source';
        }

        $typeOptions = Plugin::getInstance()->sources->getTypeOptions();

        return $this->renderTemplate('akeneo/sources/_edit', [
            'source' => $source,
            'title' => $title,
            'typeOptions' => $typeOptions,
        ]);
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

        if (!Plugin::getInstance()->sources->saveSource($source)) {
            Craft::$app->getSession()->setError('Couldn\'t save source.');

            Craft::$app->getUrlManager()->setRouteParams([
                'source' => $source,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice('Source saved.');

        if ($isNew) {
            return $this->redirect('akeneo/sources/' . $source->id . '/field-mapping');
        }

        return $this->redirectToPostedUrl($source);
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

        return $this->redirect('akeneo/sources/' . $sourceId . '/field-mapping');
    }

    public function actionRefreshAkeneoAttributes(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $sourceId = (int) $request->getRequiredBodyParam('sourceId');

        Craft::$app->getCache()->delete('akeneo_attributes_v2');

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
}
