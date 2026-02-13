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

        return true;
    }

    public function deleteSourceById(int $id): bool
    {
        $record = SourceRecord::findOne($id);

        if (!$record) {
            return false;
        }

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

    public function getCraftFieldsForSource(Source $source): array
    {
        $fields = [
            ['handle' => 'title', 'name' => 'Title', 'type' => 'field', 'supported' => true, 'fieldType' => 'Title', 'group' => 'element'],
            ['handle' => 'slug', 'name' => 'Slug', 'type' => 'field', 'supported' => true, 'fieldType' => 'Slug', 'group' => 'element'],
        ];

        if ($source->type === 'commerceProductType') {
            $fields[] = ['handle' => 'variantTitle', 'name' => 'Title', 'type' => 'field', 'supported' => true, 'fieldType' => 'Title', 'group' => 'variant'];
            $fields[] = ['handle' => 'sku', 'name' => 'SKU', 'type' => 'field', 'supported' => true, 'fieldType' => 'SKU', 'group' => 'variant'];
            $fields[] = ['handle' => 'price', 'name' => 'Price', 'type' => 'field', 'supported' => true, 'fieldType' => 'Price', 'group' => 'variant'];
        }

        // Collect custom fields from the source's field layouts
        $sourceCustomFields = [];

        if ($source->type === 'section') {
            $section = Craft::$app->getEntries()->getSectionById($source->typeId);
            if ($section) {
                foreach ($section->getEntryTypes() as $entryType) {
                    foreach ($entryType->getFieldLayout()->getCustomFields() as $customField) {
                        $sourceCustomFields[$customField->handle] = $customField;
                    }
                }
            }
        } elseif ($source->type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');
            if ($commercePlugin) {
                $productType = $commercePlugin->getProductTypes()->getProductTypeById($source->typeId);
                if ($productType) {
                    foreach ($productType->getFieldLayout()->getCustomFields() as $customField) {
                        $sourceCustomFields[$customField->handle] = $customField;
                    }
                    if (method_exists($productType, 'getVariantFieldLayout')) {
                        foreach ($productType->getVariantFieldLayout()->getCustomFields() as $customField) {
                            $sourceCustomFields[$customField->handle] = $customField;
                        }
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
