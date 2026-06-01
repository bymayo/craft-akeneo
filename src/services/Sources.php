<?php

namespace bymayo\akeneo\services;

use Craft;
use yii\base\Component;
use yii\db\Exception;

use bymayo\akeneo\Plugin;
use bymayo\akeneo\models\Source;
use bymayo\akeneo\models\FieldMapping;
use bymayo\akeneo\records\SourceRecord;
use bymayo\akeneo\records\FieldMappingRecord;

class Sources extends Component
{
    /**
     * Resolve a field by its layout-level handle within the source's field
     * layouts. Necessary for Craft 5 — when fields share settings, Craft 4→5
     * upgrades may merge multiple fields into one underlying record while
     * preserving each layout's original handle as a field-layout override.
     * `Craft::$app->getFields()->getFieldByHandle()` only knows about the
     * underlying field's global handle, so it returns null for handles that
     * exist only as layout overrides. This walks the source's entry-type /
     * product-type field layouts and matches against the layout handles that
     * `getCustomFields()` exposes.
     */
    public function resolveSourceField(Source $source, string $handle): ?\craft\base\FieldInterface
    {
        foreach ($this->getSourceFieldLayouts($source) as $layout) {
            foreach ($layout->getCustomFields() as $field) {
                if ($field->handle === $handle) {
                    return $field;
                }
            }
        }

        return null;
    }

    public function resolveMatrixNestedField(\craft\fields\Matrix $matrixField, string $entryTypeHandle, string $fieldHandle): ?\craft\base\FieldInterface
    {
        foreach ($matrixField->getEntryTypes() as $entryType) {
            if ($entryType->handle !== $entryTypeHandle) {
                continue;
            }

            foreach ($entryType->getFieldLayout()->getCustomFields() as $nestedField) {
                if ($nestedField->handle === $fieldHandle) {
                    return $nestedField;
                }
            }
        }

        return null;
    }

    private function getSourceFieldLayouts(Source $source): array
    {
        $layouts = [];

        if ($source->type === 'section') {
            $section = Craft::$app->getEntries()->getSectionById($source->typeId);
            if ($section) {
                foreach ($section->getEntryTypes() as $entryType) {
                    $layouts[] = $entryType->getFieldLayout();
                }
            }
        } elseif ($source->type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');
            if ($commercePlugin) {
                $productType = $commercePlugin->getProductTypes()->getProductTypeById($source->typeId);
                if ($productType) {
                    $layouts[] = $productType->getFieldLayout();
                    if (method_exists($productType, 'getVariantFieldLayout')) {
                        $layouts[] = $productType->getVariantFieldLayout();
                    }
                }
            }
        }

        return $layouts;
    }

    public function getEstimatedProductCount(Source $source): ?int
    {
        $settings = Plugin::getInstance()->getSettings();

        foreach (['apiUrl', 'clientId', 'secretKey', 'username', 'password'] as $key) {
            if (empty(Craft::parseEnv($settings->{$key}))) {
                return null;
            }
        }

        if (!$source->id) {
            return null;
        }

        $cacheKey = $this->_estimatedProductCountCacheKey($source->id);
        $cached = Craft::$app->getCache()->get($cacheKey);

        if ($cached !== false) {
            return $cached === '__null__' ? null : (int) $cached;
        }

        try {
            $sync = Plugin::getInstance()->sync;
            $searchFilters = $sync->buildSearchFilters($source);
            $queryParams = !empty($searchFilters) ? ['search' => $searchFilters] : [];

            $page = $sync->getClient()->getProductApi()->listPerPage(1, true, $queryParams);
            $count = $page->getCount();

            // Cache indefinitely (0 = no expiry). The count only changes when the
            // source config changes — saveSource() invalidates this key, while a
            // sync only touches lastSyncedAt via raw SQL and leaves it intact.
            Craft::$app->getCache()->set($cacheKey, $count ?? '__null__', 0);

            return $count;
        } catch (\Throwable $e) {
            Plugin::log("Failed to estimate product count for source '{$source->name}': " . $e->getMessage());
            return null;
        }
    }

    public function invalidateEstimatedProductCount(int $sourceId): void
    {
        Craft::$app->getCache()->delete($this->_estimatedProductCountCacheKey($sourceId));
    }

    private function _estimatedProductCountCacheKey(int $sourceId): string
    {
        return 'akeneo_source_estimated_count_' . $sourceId;
    }

    public function getAllSources(): array
    {
        $records = SourceRecord::find()
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $sources = [];

        foreach ($records as $record) {
            $sources[] = $this->_createSourceFromRecord($record);
        }

        return $sources;
    }

    public function getSourceById(int $id): ?Source
    {
        $record = SourceRecord::findOne($id);

        if (!$record) {
            return null;
        }

        return $this->_createSourceFromRecord($record);
    }

    public function saveSource(Source $source): bool
    {
        if (!$source->validate()) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if ($source->id) {
                $record = SourceRecord::findOne($source->id);

                if (!$record) {
                    throw new Exception('No source exists with the ID "' . $source->id . '"');
                }
            } else {
                $record = new SourceRecord();
            }

            $record->name = $source->name;
            $record->type = $source->type;
            $record->typeId = $source->typeId;
            $record->orphanedEntryAction = $source->orphanedEntryAction;
            $record->entryIdentifier = $source->entryIdentifier;
            $record->akeneoLocale = $source->akeneoLocale;
            $record->siteId = $source->siteId;
            $record->filters = $source->filters;

            $record->save(false);

            if (!$source->id) {
                $source->id = $record->id;
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->invalidateEstimatedProductCount($source->id);

        return true;
    }

    public function deleteSourceById(int $id): bool
    {
        $record = SourceRecord::findOne($id);

        if (!$record) {
            return false;
        }

        $this->invalidateEstimatedProductCount($id);

        return (bool) $record->delete();
    }

    public function getTypeOptions(): array
    {
        $options = [];

        // Sections
        $sections = Craft::$app->getEntries()->getAllSections();

        if (!empty($sections)) {
            $options[] = [
                'optgroup' => 'Sections',
            ];

            foreach ($sections as $section) {
                $options[] = [
                    'label' => $section->name,
                    'value' => 'section:' . $section->id,
                ];
            }
        }

        // Commerce Products (if Commerce is installed)
        $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

        if ($commercePlugin) {
            $productTypes = $commercePlugin->getProductTypes()->getAllProductTypes();
            $productTypeOptions = [];

            foreach ($productTypes as $productType) {
                $productTypeOptions[] = [
                    'label' => $productType->name,
                    'value' => 'commerceProductType:' . $productType->id,
                ];
            }

            if (!empty($productTypeOptions)) {
                $options[] = [
                    'optgroup' => 'Commerce Products',
                ];

                foreach ($productTypeOptions as $option) {
                    $options[] = $option;
                }
            }
        }

        return $options;
    }

    public function getTypeLabelById(string $type, int $typeId): string
    {
        if ($type === 'section') {
            $section = Craft::$app->getEntries()->getSectionById($typeId);
            return $section ? $section->name : '';
        }

        if ($type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');
            if ($commercePlugin) {
                $productType = $commercePlugin->getProductTypes()->getProductTypeById($typeId);
                return $productType ? $productType->name : '';
            }
        }

        return '';
    }

    public function getMappingsBySourceId(int $sourceId): array
    {
        $records = FieldMappingRecord::find()
            ->where(['sourceId' => $sourceId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $mappings = [];

        foreach ($records as $record) {
            $mapping = new FieldMapping();
            $mapping->id = $record->id;
            $mapping->sourceId = $record->sourceId;
            $mapping->craftFieldHandle = $record->craftFieldHandle;
            $mapping->akeneoAttribute = $record->akeneoAttribute;
            $mapping->uid = $record->uid;
            $mapping->dateCreated = $record->dateCreated;
            $mapping->dateUpdated = $record->dateUpdated;
            $mappings[] = $mapping;
        }

        return $mappings;
    }

    public function saveMappings(int $sourceId, array $mappings): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            FieldMappingRecord::deleteAll(['sourceId' => $sourceId]);

            foreach ($mappings as $mapping) {
                $record = new FieldMappingRecord();
                $record->sourceId = $sourceId;
                $record->craftFieldHandle = $mapping['craftFieldHandle'];
                $record->akeneoAttribute = $mapping['akeneoAttribute'];
                $record->save(false);
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Resolve the handle for a source's section / Commerce product type, so it
     * can be re-resolved on a different environment where the numeric IDs differ.
     */
    public function getTypeHandle(string $type, int $typeId): ?string
    {
        if ($type === 'section') {
            return Craft::$app->getEntries()->getSectionById($typeId)?->handle;
        }

        if ($type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

            if ($commercePlugin) {
                return $commercePlugin->getProductTypes()->getProductTypeById($typeId)?->handle;
            }
        }

        return null;
    }

    /**
     * Resolve a section / Commerce product type handle back to its local ID.
     */
    public function resolveTypeId(string $type, string $handle): ?int
    {
        if ($type === 'section') {
            return Craft::$app->getEntries()->getSectionByHandle($handle)?->id;
        }

        if ($type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

            if ($commercePlugin) {
                return $commercePlugin->getProductTypes()->getProductTypeByHandle($handle)?->id;
            }
        }

        return null;
    }

    /**
     * Build a portable, environment-agnostic representation of a source and its
     * field mappings for export to another environment.
     */
    public function getSourceExportData(Source $source): array
    {
        $siteHandle = $source->siteId
            ? Craft::$app->getSites()->getSiteById($source->siteId)?->handle
            : null;

        $mappings = array_map(static fn(FieldMapping $mapping) => [
            'craftFieldHandle' => $mapping->craftFieldHandle,
            'akeneoAttribute' => $mapping->akeneoAttribute,
        ], $this->getMappingsBySourceId($source->id));

        return [
            'plugin' => 'akeneo',
            'version' => 1,
            'source' => [
                'name' => $source->name,
                'type' => $source->type,
                'typeHandle' => $this->getTypeHandle($source->type, $source->typeId),
                'orphanedEntryAction' => $source->orphanedEntryAction,
                'entryIdentifier' => $source->entryIdentifier,
                'akeneoLocale' => $source->akeneoLocale,
                'siteHandle' => $siteHandle,
                'filters' => $source->filters ? json_decode($source->filters, true) : null,
            ],
            'fieldMappings' => $mappings,
        ];
    }

    /**
     * Create a new source (and its field mappings) from previously exported
     * data, re-resolving environment-specific references by handle.
     *
     * @throws \RuntimeException if the data is invalid or the type/handle can't be resolved on this environment.
     */
    public function createSourceFromImport(array $data): Source
    {
        $sourceData = $data['source'] ?? null;

        if (!is_array($sourceData)) {
            throw new \RuntimeException('The import file is missing source data.');
        }

        $type = $sourceData['type'] ?? null;
        $typeHandle = $sourceData['typeHandle'] ?? null;

        if (!$type || !$typeHandle) {
            throw new \RuntimeException('The import file is missing the source type.');
        }

        $typeId = $this->resolveTypeId($type, $typeHandle);

        if (!$typeId) {
            $label = $type === 'section' ? 'section' : 'Commerce product type';
            throw new \RuntimeException("Couldn't find a {$label} with the handle \"{$typeHandle}\" on this environment.");
        }

        $source = new Source();
        $source->name = $sourceData['name'] ?? 'Imported source';
        $source->type = $type;
        $source->typeId = $typeId;
        $source->orphanedEntryAction = $sourceData['orphanedEntryAction'] ?? 'doNothing';
        $source->entryIdentifier = $sourceData['entryIdentifier'] ?? null;
        $source->akeneoLocale = $sourceData['akeneoLocale'] ?? null;

        // Re-resolve the site by handle; fall back to the primary site if missing.
        $siteHandle = $sourceData['siteHandle'] ?? null;
        $source->siteId = $siteHandle
            ? Craft::$app->getSites()->getSiteByHandle($siteHandle)?->id
            : null;

        $filters = $sourceData['filters'] ?? null;
        $source->filters = !empty($filters) ? json_encode($filters) : null;

        if (!$this->saveSource($source)) {
            throw new \RuntimeException('Couldn\'t save imported source: ' . implode(', ', $source->getErrorSummary(true)));
        }

        $mappings = [];

        foreach ($data['fieldMappings'] ?? [] as $mapping) {
            $handle = $mapping['craftFieldHandle'] ?? null;

            if (!$handle) {
                continue;
            }

            $mappings[] = [
                'craftFieldHandle' => $handle,
                'akeneoAttribute' => $mapping['akeneoAttribute'] ?? '',
            ];
        }

        if (!empty($mappings)) {
            $this->saveMappings($source->id, $mappings);
        }

        return $source;
    }

    private const SUPPORTED_FIELD_TYPES = [
        \craft\fields\PlainText::class,
        \craft\fields\Number::class,
        \craft\fields\Email::class,
        \craft\fields\Url::class,
        \craft\fields\Dropdown::class,
        \craft\fields\RadioButtons::class,
        \craft\fields\Lightswitch::class,
        \craft\fields\Color::class,
        \craft\fields\Date::class,
        \craft\fields\Money::class,
        \craft\fields\Table::class,
        \craft\fields\Matrix::class,
        \craft\fields\Assets::class,
        \craft\fields\Entries::class,
        \craft\fields\Categories::class,
        // Optional plugin field — `::class` is a compile-time string and does not
        // autoload, so this is safe when craftcms/ckeditor isn't installed.
        \craft\ckeditor\Field::class,
    ];

    private function isFieldSupported($field): bool
    {
        foreach (self::SUPPORTED_FIELD_TYPES as $supportedType) {
            if ($field instanceof $supportedType) {
                return true;
            }
        }
        return false;
    }

    /**
     * Collect custom fields from a field layout, keyed by handle, while
     * recording the layout tab name and whether each field is required.
     */
    private function collectLayoutFields(\craft\models\FieldLayout $fieldLayout, array &$sourceCustomFields, array &$fieldTabs, array &$fieldRequired): void
    {
        foreach ($fieldLayout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (!$element instanceof \craft\fieldlayoutelements\CustomField) {
                    continue;
                }

                $field = $element->getField();

                if ($field !== null && !isset($sourceCustomFields[$field->handle])) {
                    $sourceCustomFields[$field->handle] = $field;
                    $fieldTabs[$field->handle] = $tab->name;
                    $fieldRequired[$field->handle] = (bool) $element->required;
                }
            }
        }
    }

    public function getCraftFieldsForSource(Source $source): array
    {
        $fields = [
            ['handle' => 'title', 'name' => 'Title', 'type' => 'field', 'supported' => true, 'fieldType' => 'Title', 'group' => 'element', 'required' => true],
            ['handle' => 'slug', 'name' => 'Slug', 'type' => 'field', 'supported' => true, 'fieldType' => 'Slug', 'group' => 'element', 'required' => true],
        ];

        if ($source->type === 'commerceProductType') {
            $fields[] = ['handle' => 'variantTitle', 'name' => 'Title', 'type' => 'field', 'supported' => true, 'fieldType' => 'Title', 'group' => 'variant'];
            $fields[] = ['handle' => 'sku', 'name' => 'SKU', 'type' => 'field', 'supported' => true, 'fieldType' => 'SKU', 'group' => 'variant'];
            $fields[] = ['handle' => 'price', 'name' => 'Price', 'type' => 'field', 'supported' => true, 'fieldType' => 'Price', 'group' => 'variant'];
        }

        // Collect custom fields from the source's field layouts, tracking
        // which field layout tab each field belongs to.
        $sourceCustomFields = [];
        $fieldTabs = [];
        $fieldRequired = [];

        if ($source->type === 'section') {
            $section = Craft::$app->getEntries()->getSectionById($source->typeId);
            if ($section) {
                foreach ($section->getEntryTypes() as $entryType) {
                    $this->collectLayoutFields($entryType->getFieldLayout(), $sourceCustomFields, $fieldTabs, $fieldRequired);
                }
            }
        } elseif ($source->type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');
            if ($commercePlugin) {
                $productType = $commercePlugin->getProductTypes()->getProductTypeById($source->typeId);
                if ($productType) {
                    $this->collectLayoutFields($productType->getFieldLayout(), $sourceCustomFields, $fieldTabs, $fieldRequired);
                    if (method_exists($productType, 'getVariantFieldLayout')) {
                        $this->collectLayoutFields($productType->getVariantFieldLayout(), $sourceCustomFields, $fieldTabs, $fieldRequired);
                    }
                }
            }
        }

        foreach ($sourceCustomFields as $field) {
            $fieldData = [
                'handle' => $field->handle,
                'name' => $field->name,
                'type' => 'field',
                'supported' => $this->isFieldSupported($field),
                'fieldType' => $field::displayName(),
                'tab' => $fieldTabs[$field->handle] ?? null,
                'required' => $fieldRequired[$field->handle] ?? false,
            ];

            if ($field instanceof \craft\fields\Table) {
                $fieldData['type'] = 'table';
                $fieldData['columns'] = [];

                foreach ($field->columns as $colId => $col) {
                    $fieldData['columns'][$colId] = [
                        'heading' => $col['heading'] ?? $colId,
                        'handle' => $col['handle'] ?? $colId,
                    ];
                }
            } elseif ($field instanceof \craft\fields\Assets) {
                $fieldData['type'] = 'asset';
                $fieldData['maxRelations'] = $field->maxRelations;
            } elseif ($field instanceof \craft\fields\Entries) {
                $fieldData['type'] = 'entries';
                $fieldData['maxRelations'] = $field->maxRelations;
            } elseif ($field instanceof \craft\fields\Categories) {
                $fieldData['type'] = 'categories';
                $fieldData['maxRelations'] = $field->maxRelations;
            } elseif ($field instanceof \craft\fields\Matrix) {
                $fieldData['type'] = 'matrix';
                $fieldData['entryTypes'] = [];

                foreach ($field->getEntryTypes() as $entryType) {
                    $entryTypeData = [
                        'handle' => $entryType->handle,
                        'name' => $entryType->name,
                        'fields' => [],
                    ];

                    foreach ($entryType->getFieldLayout()->getCustomFields() as $nestedField) {
                        $nestedFieldData = [
                            'handle' => $nestedField->handle,
                            'name' => $nestedField->name,
                            'type' => 'field',
                            'supported' => $this->isFieldSupported($nestedField),
                            'fieldType' => $nestedField::displayName(),
                        ];

                        if ($nestedField instanceof \craft\fields\Table) {
                            $nestedFieldData['type'] = 'table';
                            $nestedFieldData['columns'] = [];
                            foreach ($nestedField->columns as $colId => $col) {
                                $nestedFieldData['columns'][$colId] = [
                                    'heading' => $col['heading'] ?? $colId,
                                    'handle' => $col['handle'] ?? $colId,
                                ];
                            }
                        } elseif ($nestedField instanceof \craft\fields\Assets) {
                            $nestedFieldData['type'] = 'asset';
                            $nestedFieldData['maxRelations'] = $nestedField->maxRelations;
                        } elseif ($nestedField instanceof \craft\fields\Entries) {
                            $nestedFieldData['type'] = 'entries';
                            $nestedFieldData['maxRelations'] = $nestedField->maxRelations;
                        } elseif ($nestedField instanceof \craft\fields\Categories) {
                            $nestedFieldData['type'] = 'categories';
                            $nestedFieldData['maxRelations'] = $nestedField->maxRelations;
                        }

                        $entryTypeData['fields'][] = $nestedFieldData;
                    }

                    $fieldData['entryTypes'][] = $entryTypeData;
                }
            }

            $fields[] = $fieldData;
        }

        return $fields;
    }

    public function getAkeneoAttributes(): array
    {
        $cacheKey = 'akeneo_attributes';
        $cache = Craft::$app->getCache();

        $attributes = $cache->get($cacheKey);

        if ($attributes !== false) {
            return $attributes;
        }

        $client = Plugin::getInstance()->sync->getClient();
        $attributes = [];

        foreach ($client->getAttributeApi()->all() as $attribute) {
            $label = $attribute['labels']['en_US'] ?? $attribute['labels']['en_GB'] ?? $attribute['code'];
            $type = $attribute['type'] ?? 'other';
            $attributes[] = [
                'code' => $attribute['code'],
                'label' => $label . ' (' . $attribute['code'] . ')',
                'type' => $type,
            ];
        }

        usort($attributes, function ($a, $b) {
            $typeCompare = strcasecmp($a['type'], $b['type']);
            if ($typeCompare !== 0) {
                return $typeCompare;
            }
            return strcasecmp($a['label'], $b['label']);
        });

        $cacheDuration = Plugin::getInstance()->getSettings()->attributeCacheDuration;
        $cache->set($cacheKey, $attributes, $cacheDuration);

        return $attributes;
    }

    public function getAkeneoLocales(): array
    {
        $cacheKey = 'akeneo_locales';
        $cache = Craft::$app->getCache();

        $locales = $cache->get($cacheKey);

        if ($locales !== false) {
            return $locales;
        }

        $client = Plugin::getInstance()->sync->getClient();
        $locales = [];

        foreach ($client->getLocaleApi()->all() as $locale) {
            if (!($locale['enabled'] ?? false)) {
                continue;
            }

            $locales[] = [
                'code' => $locale['code'],
                'label' => $locale['code'],
            ];
        }

        usort($locales, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        $cacheDuration = Plugin::getInstance()->getSettings()->attributeCacheDuration;
        $cache->set($cacheKey, $locales, $cacheDuration);

        return $locales;
    }

    public function updateLastSyncedAt(int $sourceId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%akeneo_sources}}', [
                'lastSyncedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
            ], ['id' => $sourceId])
            ->execute();
    }

    public function updateAllLastSyncedAt(): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%akeneo_sources}}', [
                'lastSyncedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
            ])
            ->execute();
    }

    private function _createSourceFromRecord(SourceRecord $record): Source
    {
        $source = new Source();
        $source->id = $record->id;
        $source->name = $record->name;
        $source->type = $record->type;
        $source->typeId = $record->typeId;
        $source->orphanedEntryAction = $record->orphanedEntryAction;
        $source->entryIdentifier = $record->entryIdentifier;
        $source->akeneoLocale = $record->akeneoLocale;
        $source->siteId = $record->siteId ? (int) $record->siteId : null;
        $source->filters = $record->filters;
        $source->lastSyncedAt = $record->lastSyncedAt;
        $source->uid = $record->uid;
        $source->dateCreated = $record->dateCreated;
        $source->dateUpdated = $record->dateUpdated;

        return $source;
    }
}
