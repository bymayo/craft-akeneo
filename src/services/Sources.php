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

        // Commerce Product Types (if Commerce is installed)
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
                    'optgroup' => 'Commerce Product Types',
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

    public function getCraftFieldsForSource(Source $source): array
    {
        $fields = [
            ['handle' => 'title', 'name' => 'Title'],
            ['handle' => 'slug', 'name' => 'Slug'],
        ];

        $seenHandles = ['title' => true, 'slug' => true];

        if ($source->type === 'section') {
            $section = Craft::$app->getEntries()->getSectionById($source->typeId);

            if ($section) {
                foreach ($section->getEntryTypes() as $entryType) {
                    $fieldLayout = $entryType->getFieldLayout();

                    if ($fieldLayout) {
                        foreach ($fieldLayout->getCustomFields() as $field) {
                            if (!isset($seenHandles[$field->handle])) {
                                $fields[] = [
                                    'handle' => $field->handle,
                                    'name' => $field->name,
                                ];
                                $seenHandles[$field->handle] = true;
                            }
                        }
                    }
                }
            }
        } elseif ($source->type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

            if ($commercePlugin) {
                $productType = $commercePlugin->getProductTypes()->getProductTypeById($source->typeId);

                if ($productType) {
                    $fieldLayout = $productType->getFieldLayout();

                    if ($fieldLayout) {
                        foreach ($fieldLayout->getCustomFields() as $field) {
                            if (!isset($seenHandles[$field->handle])) {
                                $fields[] = [
                                    'handle' => $field->handle,
                                    'name' => $field->name,
                                ];
                                $seenHandles[$field->handle] = true;
                            }
                        }
                    }
                }
            }
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
            $attributes[] = [
                'code' => $attribute['code'],
                'label' => $label . ' (' . $attribute['code'] . ')',
            ];
        }

        usort($attributes, function ($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        $cache->set($cacheKey, $attributes, 300);

        return $attributes;
    }

    private function _createSourceFromRecord(SourceRecord $record): Source
    {
        $source = new Source();
        $source->id = $record->id;
        $source->name = $record->name;
        $source->type = $record->type;
        $source->typeId = $record->typeId;
        $source->uid = $record->uid;
        $source->dateCreated = $record->dateCreated;
        $source->dateUpdated = $record->dateUpdated;

        return $source;
    }
}
