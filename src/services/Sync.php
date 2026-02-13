<?php

namespace bymayo\akeneo\services;

use bymayo\akeneo\Plugin;

use Craft;
use yii\base\Component;

use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;

use bymayo\akeneo\jobs\FetchProducts;
use bymayo\akeneo\models\Source;

use craft\base\Element;
use craft\elements\Entry;
use craft\elements\Asset;
use craft\elements\Category;
use craft\fields\Assets as AssetsField;
use craft\fields\Entries as EntriesField;
use craft\fields\Categories as CategoriesField;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\models\Volume;

/**
 * Auth service
 */
class Sync extends Component
{

    private $client;

    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->client = $this->connect();
    }

    public function getClient()
    {
        return $this->client;
    }

    public function connect()
    {

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

        return $client;

    }

    public function syncBySource(Source $source, bool $syncImages): void
    {
        Queue::push(new FetchProducts([
            'sourceId' => $source->id,
            'syncImages' => $syncImages,
        ]));
    }

    public function buildSearchFilters(Source $source): array
    {
        $filtersJson = $source->filters;

        if (empty($filtersJson)) {
            return [];
        }

        $filters = json_decode($filtersJson, true);

        if (empty($filters) || !is_array($filters)) {
            return [];
        }

        $searchBuilder = new SearchBuilder();

        foreach ($filters as $filter) {
            $attribute = $filter['attribute'] ?? '';
            $operator = $filter['operator'] ?? '';
            $value = $filter['value'] ?? '';

            if (empty($attribute) || empty($operator)) {
                continue;
            }

            // Operators that take no value
            if (in_array($operator, ['EMPTY', 'NOT EMPTY'])) {
                $searchBuilder->addFilter($attribute, $operator);
                continue;
            }

            // Convert value for specific operators
            $parsedValue = $this->parseFilterValue($value, $operator);

            $searchBuilder->addFilter($attribute, $operator, $parsedValue);
        }

        return $searchBuilder->getFilters();
    }

    private function parseFilterValue(string $value, string $operator): mixed
    {
        // Array operators: split comma-separated values
        if (in_array($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'])) {
            return array_map('trim', explode(',', $value));
        }

        // Boolean values
        if (strtolower($value) === 'true') {
            return true;
        }

        if (strtolower($value) === 'false') {
            return false;
        }

        // Numeric values
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float) $value : (int) $value;
        }

        return $value;
    }

    public function createEntryFromMappings(Source $source, array $data, bool $syncImages): ?int
    {
        $mappings = Plugin::getInstance()->sources->getMappingsBySourceId($source->id);

        if (empty($mappings)) {
            Plugin::log("No field mappings found for source '{$source->name}' (ID: {$source->id})");
            return null;
        }

        // Build attribute type lookup: code => type
        $akeneoAttributes = Plugin::getInstance()->sources->getAkeneoAttributes();
        $attributeTypes = [];
        foreach ($akeneoAttributes as $attr) {
            $attributeTypes[$attr['code']] = $attr['type'];
        }

        $locale = $source->akeneoLocale ?? 'en_GB';
        $values = $data['values'] ?? [];

        // Resolve identifier value to find existing entry
        $identifierHandle = $source->entryIdentifier;
        $identifierValue = null;

        if ($identifierHandle) {
            foreach ($mappings as $mapping) {
                if ($mapping->craftFieldHandle === $identifierHandle) {
                    $identifierValue = $this->resolveMappingValue(
                        $mapping->akeneoAttribute, $values, $attributeTypes, $locale
                    );
                    break;
                }
            }
        }

        // Query existing element or create new
        $element = null;
        $siteId = $source->siteId ?: Craft::$app->getSites()->getPrimarySite()->id;

        if ($source->type === 'section') {
            $section = Craft::$app->getEntries()->getSectionById($source->typeId);

            if (!$section) {
                Plugin::log("Section with ID {$source->typeId} not found for source '{$source->name}'");
                return null;
            }

            if ($identifierValue && $identifierHandle) {
                $query = Entry::find()
                    ->sectionId($section->id)
                    ->siteId($siteId)
                    ->status(['live', 'pending', 'expired', 'disabled']);

                if ($identifierHandle === 'title') {
                    $query->title($identifierValue);
                } elseif ($identifierHandle === 'slug') {
                    $query->slug($identifierValue);
                } else {
                    $query->{$identifierHandle}($identifierValue);
                }

                $element = $query->one();
            }

            if (!$element) {
                $element = new Entry();
                $element->sectionId = $section->id;
                $element->siteId = $siteId;
            }
        } elseif ($source->type === 'commerceProductType') {
            $commercePlugin = Craft::$app->plugins->getPlugin('commerce');

            if (!$commercePlugin) {
                Plugin::log("Commerce plugin is not installed. Cannot sync source '{$source->name}' with type 'commerceProductType'.");
                return null;
            }

            if ($identifierValue && $identifierHandle) {
                $query = \craft\commerce\elements\Product::find()
                    ->typeId($source->typeId)
                    ->siteId($siteId)
                    ->status(null);

                if ($identifierHandle === 'title') {
                    $query->title($identifierValue);
                } elseif ($identifierHandle === 'slug') {
                    $query->slug($identifierValue);
                } else {
                    $query->{$identifierHandle}($identifierValue);
                }

                $element = $query->one();
            }

            if (!$element) {
                $element = new \craft\commerce\elements\Product();
                $element->typeId = $source->typeId;
                $element->siteId = $siteId;
            }
        } else {
            Plugin::log("Unsupported source type '{$source->type}' for source '{$source->name}'");
            return null;
        }

        $element->enabled = true;

        // Collect variant-specific data (SKU/Price) separately for Commerce products
        $variantData = [];

        // Apply each field mapping
        foreach ($mappings as $mapping) {
            $handle = $mapping->craftFieldHandle;
            $akeneoAttr = $mapping->akeneoAttribute;

            // Check if JSON-encoded (table or matrix mapping)
            $decoded = json_decode($akeneoAttr, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (array_is_list($decoded)) {
                    // Check first element to distinguish: strings = multi-relational, objects = table rows
                    if (!empty($decoded) && is_string($decoded[0])) {
                        $craftField = Craft::$app->getFields()->getFieldByHandle($handle);

                        if ($craftField instanceof EntriesField) {
                            // Multi-entries: resolve each attribute and look up entries
                            $allIds = [];
                            foreach ($decoded as $akeneoCode) {
                                $codeAttrType = $attributeTypes[$akeneoCode] ?? 'other';
                                $resolved = Plugin::getInstance()->attributes->resolveValue(
                                    $akeneoCode, $codeAttrType, $values, $this->client, $locale
                                );
                                if ($resolved !== null) {
                                    $ids = $this->resolveEntriesFieldValue($craftField, $resolved);
                                    $allIds = array_merge($allIds, $ids);
                                }
                            }
                            if (!empty($allIds)) {
                                $element->setFieldValue($handle, $allIds);
                            }
                        } elseif ($craftField instanceof CategoriesField) {
                            // Multi-categories: resolve each attribute and look up categories
                            $allIds = [];
                            foreach ($decoded as $akeneoCode) {
                                $codeAttrType = $attributeTypes[$akeneoCode] ?? 'other';
                                $resolved = Plugin::getInstance()->attributes->resolveValue(
                                    $akeneoCode, $codeAttrType, $values, $this->client, $locale
                                );
                                if ($resolved !== null) {
                                    $ids = $this->resolveCategoriesFieldValue($craftField, $resolved);
                                    $allIds = array_merge($allIds, $ids);
                                }
                            }
                            if (!empty($allIds)) {
                                $element->setFieldValue($handle, $allIds);
                            }
                        } else {
                            // Multi-asset field
                            if ($syncImages) {
                                $volume = $this->getVolumeForField($handle);
                                $allAssetIds = [];
                                foreach ($decoded as $assetCode) {
                                    $assetIds = $this->resolveAssetField($assetCode, $values, $volume);
                                    $allAssetIds = array_merge($allAssetIds, $assetIds);
                                }
                                if (!empty($allAssetIds)) {
                                    $element->setFieldValue($handle, $allAssetIds);
                                }
                            }
                        }
                    } else {
                        // Table field
                        $tableData = $this->resolveTableRows($decoded, $values, $attributeTypes, $locale);
                        $element->setFieldValue($handle, $tableData);
                    }
                } else {
                    // Matrix field
                    $matrixData = $this->resolveMatrixMapping($decoded, $values, $attributeTypes, $syncImages, $locale, $handle);
                    $element->setFieldValue($handle, $matrixData);
                }
                continue;
            }

            // Static value
            if (str_starts_with($akeneoAttr, 'static:')) {
                $value = substr($akeneoAttr, 7);

                if (in_array($handle, ['variantTitle', 'sku', 'price']) && $source->type === 'commerceProductType') {
                    $variantData[$handle] = $value;
                    continue;
                }

                $this->setElementFieldValue($element, $handle, $value);
                continue;
            }

            // Asset field
            $attrType = $attributeTypes[$akeneoAttr] ?? 'other';

            if ($attrType === 'pim_catalog_asset_collection') {
                if ($syncImages) {
                    $volume = $this->getVolumeForField($handle);
                    $assetIds = $this->resolveAssetField($akeneoAttr, $values, $volume);
                    if (!empty($assetIds)) {
                        $element->setFieldValue($handle, $assetIds);
                    }
                }
                continue;
            }

            // Regular Akeneo attribute
            $value = Plugin::getInstance()->attributes->resolveValue(
                $akeneoAttr, $attrType, $values, $this->client, $locale
            );

            if ($value !== null) {
                if (in_array($handle, ['variantTitle', 'sku', 'price']) && $source->type === 'commerceProductType') {
                    $variantData[$handle] = $value;
                    continue;
                }

                $this->setElementFieldValue($element, $handle, $value);
            }
        }

        // Save
        if (!Craft::$app->elements->saveElement($element)) {
            $errors = implode(', ', $element->getErrorSummary(true));
            Plugin::log("Failed to save element for source '{$source->name}': {$errors}");
            Craft::error("Failed to save element for source '{$source->name}': {$errors}", __METHOD__);
            return null;
        }

        // For Commerce Products, create/update the default variant after saving
        if ($source->type === 'commerceProductType' && $element instanceof \craft\commerce\elements\Product) {
            Plugin::log("Syncing default variant for product ID {$element->id} with data: " . json_encode($variantData));
            $this->syncDefaultVariant($element, $variantData);
        }

        return $element->id;
    }

    private function setElementFieldValue(Element $element, string $handle, mixed $value): void
    {
        if ($handle === 'title') {
            $element->title = $value;
        } elseif ($handle === 'slug') {
            $element->slug = $value;
        } else {
            $field = Craft::$app->getFields()->getFieldByHandle($handle);

            if ($field instanceof EntriesField) {
                $value = $this->resolveEntriesFieldValue($field, $value);
            } elseif ($field instanceof CategoriesField) {
                $value = $this->resolveCategoriesFieldValue($field, $value);
            }

            $element->setFieldValue($handle, $value);
        }
    }

    private function resolveEntriesFieldValue(EntriesField $field, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $titles = array_map('trim', explode(',', (string) $value));
        $ids = [];

        // Resolve allowed section IDs from the field's sources
        $sectionIds = [];
        $sources = $field->sources;

        if ($sources && $sources !== '*') {
            foreach ($sources as $source) {
                if (str_starts_with($source, 'section:')) {
                    $uid = substr($source, 8);
                    $section = Craft::$app->getEntries()->getSectionByUid($uid);

                    if ($section) {
                        $sectionIds[] = $section->id;
                    }
                }
            }
        }

        foreach ($titles as $title) {
            if ($title === '') {
                continue;
            }

            $query = Entry::find()->title($title)->status(null)->limit(1);

            if (!empty($sectionIds)) {
                $query->sectionId($sectionIds);
            }

            $entry = $query->one();

            // Create the entry if it doesn't exist
            if (!$entry && !empty($sectionIds)) {
                $entry = new Entry();
                $entry->sectionId = $sectionIds[0];
                $entry->title = $title;

                if (!Craft::$app->elements->saveElement($entry)) {
                    Plugin::log("Failed to create entry '{$title}': " . implode(', ', $entry->getErrorSummary(true)));
                    continue;
                }
            }

            if ($entry) {
                $ids[] = $entry->id;
            }
        }

        return $ids;
    }

    private function resolveCategoriesFieldValue(CategoriesField $field, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $titles = array_map('trim', explode(',', (string) $value));
        $ids = [];

        // Resolve the category group from the field's source
        $group = null;
        $source = $field->source;

        if ($source && str_starts_with($source, 'group:')) {
            $uid = substr($source, 6);
            $group = Craft::$app->getCategories()->getGroupByUid($uid);
        }

        foreach ($titles as $title) {
            if ($title === '') {
                continue;
            }

            $query = Category::find()->title($title)->status(null)->limit(1);

            if ($group) {
                $query->groupId($group->id);
            }

            $category = $query->one();

            // Create the category if it doesn't exist
            if (!$category && $group) {
                $category = new Category();
                $category->groupId = $group->id;
                $category->title = $title;

                if (!Craft::$app->elements->saveElement($category)) {
                    Plugin::log("Failed to create category '{$title}': " . implode(', ', $category->getErrorSummary(true)));
                    continue;
                }
            }

            if ($category) {
                $ids[] = $category->id;
            }
        }

        return $ids;
    }

    private function resolveMappingValue(string $akeneoAttr, array $values, array $attributeTypes, string $locale = 'en_GB'): mixed
    {
        if (str_starts_with($akeneoAttr, 'static:')) {
            return substr($akeneoAttr, 7);
        }

        $attrType = $attributeTypes[$akeneoAttr] ?? 'other';

        return Plugin::getInstance()->attributes->resolveValue(
            $akeneoAttr, $attrType, $values, $this->client, $locale
        );
    }

    private function resolveTableRows(array $rows, array $values, array $attributeTypes, string $locale = 'en_GB'): array
    {
        $tableData = [];

        foreach ($rows as $row) {
            $rowData = [];

            foreach ($row as $colId => $colMapping) {
                $static = $colMapping['static'] ?? '';
                $akeneo = $colMapping['akeneo'] ?? '';

                if (!empty($static)) {
                    $rowData[$colId] = $static;
                } elseif (!empty($akeneo)) {
                    $resolved = $this->resolveMappingValue($akeneo, $values, $attributeTypes, $locale);
                    $rowData[$colId] = $resolved !== null ? (string) $resolved : '';
                } else {
                    $rowData[$colId] = '';
                }
            }

            // Only include rows that have at least one non-empty value
            $hasData = false;
            foreach ($rowData as $val) {
                if ($val !== '') {
                    $hasData = true;
                    break;
                }
            }

            if ($hasData) {
                $tableData[] = $rowData;
            }
        }

        return $tableData;
    }

    private function resolveMatrixMapping(array $matrixData, array $values, array $attributeTypes, bool $syncImages, string $locale = 'en_GB', ?string $matrixFieldHandle = null): array
    {
        $result = [];
        $blockIndex = 0;

        foreach ($matrixData as $entryTypeHandle => $entryRows) {
            foreach ($entryRows as $row) {
                $fields = [];

                foreach ($row as $fieldHandle => $fieldValue) {
                    // Nested array field (table rows, multi-asset, or multi-entries/categories)
                    if (is_array($fieldValue)) {
                        if (!empty($fieldValue) && is_string($fieldValue[0] ?? null)) {
                            $nestedField = $this->getNestedField($matrixFieldHandle, $entryTypeHandle, $fieldHandle);

                            if ($nestedField instanceof EntriesField) {
                                // Multi-entries (array of akeneo codes)
                                $allIds = [];
                                foreach ($fieldValue as $akeneoCode) {
                                    $codeAttrType = $attributeTypes[$akeneoCode] ?? 'other';
                                    $resolved = Plugin::getInstance()->attributes->resolveValue(
                                        $akeneoCode, $codeAttrType, $values, $this->client, $locale
                                    );
                                    if ($resolved !== null) {
                                        $ids = $this->resolveEntriesFieldValue($nestedField, $resolved);
                                        $allIds = array_merge($allIds, $ids);
                                    }
                                }
                                if (!empty($allIds)) {
                                    $fields[$fieldHandle] = $allIds;
                                }
                            } elseif ($nestedField instanceof CategoriesField) {
                                // Multi-categories (array of akeneo codes)
                                $allIds = [];
                                foreach ($fieldValue as $akeneoCode) {
                                    $codeAttrType = $attributeTypes[$akeneoCode] ?? 'other';
                                    $resolved = Plugin::getInstance()->attributes->resolveValue(
                                        $akeneoCode, $codeAttrType, $values, $this->client, $locale
                                    );
                                    if ($resolved !== null) {
                                        $ids = $this->resolveCategoriesFieldValue($nestedField, $resolved);
                                        $allIds = array_merge($allIds, $ids);
                                    }
                                }
                                if (!empty($allIds)) {
                                    $fields[$fieldHandle] = $allIds;
                                }
                            } else {
                                // Multi-asset field (array of akeneo codes)
                                if ($syncImages) {
                                    $volume = $matrixFieldHandle ? $this->getVolumeForField($fieldHandle, $matrixFieldHandle, $entryTypeHandle) : null;
                                    $allAssetIds = [];
                                    foreach ($fieldValue as $assetCode) {
                                        $assetIds = $this->resolveAssetField($assetCode, $values, $volume);
                                        $allAssetIds = array_merge($allAssetIds, $assetIds);
                                    }
                                    if (!empty($allAssetIds)) {
                                        $fields[$fieldHandle] = $allAssetIds;
                                    }
                                }
                            }
                        } else {
                            // Table field (array of row objects)
                            $fields[$fieldHandle] = $this->resolveTableRows($fieldValue, $values, $attributeTypes, $locale);
                        }
                        continue;
                    }

                    // Static value
                    if (str_starts_with($fieldValue, 'static:')) {
                        $fields[$fieldHandle] = substr($fieldValue, 7);
                        continue;
                    }

                    // Asset field
                    $attrType = $attributeTypes[$fieldValue] ?? 'other';

                    if ($attrType === 'pim_catalog_asset_collection') {
                        if ($syncImages) {
                            $volume = $matrixFieldHandle ? $this->getVolumeForField($fieldHandle, $matrixFieldHandle, $entryTypeHandle) : null;
                            $assetIds = $this->resolveAssetField($fieldValue, $values, $volume);
                            if (!empty($assetIds)) {
                                $fields[$fieldHandle] = $assetIds;
                            }
                        }
                        continue;
                    }

                    // Regular Akeneo attribute
                    $resolved = Plugin::getInstance()->attributes->resolveValue(
                        $fieldValue, $attrType, $values, $this->client, $locale
                    );

                    if ($resolved !== null) {
                        // Check if this nested field is an Entries or Categories field
                        $nestedField = $this->getNestedField($matrixFieldHandle, $entryTypeHandle, $fieldHandle);

                        if ($nestedField instanceof EntriesField) {
                            $resolved = $this->resolveEntriesFieldValue($nestedField, $resolved);
                        } elseif ($nestedField instanceof CategoriesField) {
                            $resolved = $this->resolveCategoriesFieldValue($nestedField, $resolved);
                        }

                        $fields[$fieldHandle] = $resolved;
                    }
                }

                if (!empty($fields)) {
                    $result['new' . $blockIndex] = [
                        'type' => $entryTypeHandle,
                        'enabled' => true,
                        'fields' => $fields,
                    ];
                    $blockIndex++;
                }
            }
        }

        return $result;
    }

    private function resolveAssetField(string $akeneoCode, array $values, ?Volume $volume = null): array
    {
        $assetIds = [];

        if (!array_key_exists($akeneoCode, $values)) {
            return $assetIds;
        }

        $attributeData = $values[$akeneoCode][0] ?? null;

        if (!$attributeData || empty($attributeData['data'])) {
            return $assetIds;
        }

        $referenceDataName = $attributeData['reference_data_name'] ?? null;
        $assetCodes = $attributeData['data'];

        if (!$referenceDataName || !is_array($assetCodes)) {
            return $assetIds;
        }

        foreach ($assetCodes as $assetCode) {
            try {
                $assetData = $this->client->getAssetManagerApi()->get($referenceDataName, $assetCode);
                $assetValues = $assetData['values'] ?? [];

                // Find the first URL in the asset values
                $url = null;
                $urlKey = null;

                foreach ($assetValues as $key => $valueArray) {
                    $data = $valueArray[0]['data'] ?? null;
                    if (is_string($data) && (str_starts_with($data, 'http://') || str_starts_with($data, 'https://'))) {
                        $url = $data;
                        $urlKey = $key;
                        break;
                    }
                }

                if ($url) {
                    $filename = basename(parse_url($url, PHP_URL_PATH));
                    $subfolder = $urlKey ? StringHelper::toKebabCase($urlKey) : $akeneoCode;
                    $asset = $this->createAsset($filename, $url, $subfolder, $volume);

                    if ($asset) {
                        $assetIds[] = $asset->id;
                    }
                }
            } catch (\Exception $e) {
                Plugin::log("Failed to resolve asset '{$assetCode}' for '{$akeneoCode}': " . $e->getMessage());
            }
        }

        return $assetIds;
    }

    private function getVolumeForField(string $fieldHandle, ?string $matrixFieldHandle = null, ?string $entryTypeHandle = null): ?Volume
    {
        $field = null;

        if ($matrixFieldHandle && $entryTypeHandle) {
            // Nested field inside a matrix
            $matrixField = Craft::$app->getFields()->getFieldByHandle($matrixFieldHandle);
            if ($matrixField instanceof \craft\fields\Matrix) {
                foreach ($matrixField->getEntryTypes() as $entryType) {
                    if ($entryType->handle === $entryTypeHandle) {
                        foreach ($entryType->getFieldLayout()->getCustomFields() as $nestedField) {
                            if ($nestedField->handle === $fieldHandle) {
                                $field = $nestedField;
                                break 2;
                            }
                        }
                    }
                }
            }
        } else {
            $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        }

        if (!$field instanceof AssetsField) {
            return null;
        }

        $sourceKey = $field->defaultUploadLocationSource;

        if (!$sourceKey) {
            return null;
        }

        $parts = explode(':', $sourceKey, 2);

        if (count($parts) !== 2) {
            return null;
        }

        return Craft::$app->getVolumes()->getVolumeByUid($parts[1]);
    }

    private function getNestedField(?string $matrixFieldHandle, string $entryTypeHandle, string $fieldHandle): ?\craft\base\FieldInterface
    {
        if (!$matrixFieldHandle) {
            return Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        }

        $matrixField = Craft::$app->getFields()->getFieldByHandle($matrixFieldHandle);

        if (!$matrixField instanceof \craft\fields\Matrix) {
            return null;
        }

        foreach ($matrixField->getEntryTypes() as $entryType) {
            if ($entryType->handle === $entryTypeHandle) {
                foreach ($entryType->getFieldLayout()->getCustomFields() as $nestedField) {
                    if ($nestedField->handle === $fieldHandle) {
                        return $nestedField;
                    }
                }
            }
        }

        return null;
    }

    private function syncDefaultVariant(\craft\commerce\elements\Product $product, array $variantData = []): void
    {
        $variants = $product->getVariants();
        $variant = null;

        // Find existing default variant
        foreach ($variants as $v) {
            if ($v->isDefault) {
                $variant = $v;
                break;
            }
        }

        // Create new variant if none exists
        if (!$variant) {
            $variant = new \craft\commerce\elements\Variant();
            $variant->productId = $product->id;
            $variant->isDefault = true;
        }

        // Set Variant Title: mapped value → existing → fallback from product title
        if (isset($variantData['variantTitle'])) {
            $variant->title = (string) $variantData['variantTitle'];
        } elseif (!$variant->title) {
            $variant->title = $product->title ?? 'Default';
        }

        // Set SKU: mapped value → existing → fallback from product slug/title
        if (isset($variantData['sku'])) {
            $variant->sku = (string) $variantData['sku'];
        } elseif (!$variant->sku) {
            $variant->sku = $product->slug ?: StringHelper::toKebabCase($product->title ?? 'default');
        }

        // Set Price: mapped value → existing → 0
        $price = isset($variantData['price']) ? $this->resolvePrice($variantData['price']) : null;

        if ($price !== null) {
            $variant->basePrice = $price;
        } elseif (!$variant->basePrice) {
            $variant->basePrice = 0;
        }

        Plugin::log("Saving variant: sku={$variant->sku}, basePrice={$variant->basePrice}, productId={$variant->productId}, isDefault=" . ($variant->isDefault ? 'true' : 'false'));

        if (!Craft::$app->elements->saveElement($variant)) {
            $errors = implode(', ', $variant->getErrorSummary(true));
            Plugin::log("Failed to save default variant for product ID {$product->id}: {$errors}");
        } else {
            Plugin::log("Successfully saved variant ID {$variant->id} for product ID {$product->id}");
        }
    }

    private function resolvePrice(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_array($value) && isset($value[0]['amount'])) {
            return (float) $value[0]['amount'];
        }

        return 0;
    }

    public function createAsset($filename, $url, $subfolderName = null, ?Volume $volume = null)
    {
        $settings = Plugin::getInstance()->getSettings();
        $folderName = $settings->assetFolderName;
        $folderSlug = StringHelper::toKebabCase($folderName);

        if (!$volume) {
            Plugin::log("No volume provided for asset '{$filename}'. Ensure the Asset field has a default upload location configured.");
            return null;
        }

        $rootFolder = Craft::$app->assets->getRootFolderByVolumeId($volume->id);

        // Create Akeneo folder
        $akeneoSubfolder = Craft::$app->assets->findFolder([
            'parentId' => $rootFolder->id,
            'name' => $folderName,
        ]);

        if (!$akeneoSubfolder) {

            $akeneoSubfolder = new \craft\models\VolumeFolder();
            $akeneoSubfolder->name = $folderName;
            $akeneoSubfolder->parentId = $rootFolder->id;
            $akeneoSubfolder->volumeId = $volume->id;
            $akeneoSubfolder->path = $folderSlug . '/';

            if (!Craft::$app->assets->createFolder($akeneoSubfolder)) {
                Craft::error('Failed to create Akeneo subfolder', __METHOD__);
                return null;
            }

        }

        $folderId = $akeneoSubfolder->id;

        $subfolderName = StringHelper::toKebabCase($subfolderName);

        if ($subfolderName) {

            $subfolder = Craft::$app->assets->findFolder([
                'parentId' => $akeneoSubfolder->id,
                'name' => $subfolderName,
            ]);

            if (!$subfolder) {

                $subfolder = new \craft\models\VolumeFolder();
                $subfolder->name = $subfolderName;
                $subfolder->parentId = $akeneoSubfolder->id;
                $subfolder->volumeId = $volume->id;
                $subfolder->path = $akeneoSubfolder->path . $subfolderName . '/';

                if (!Craft::$app->assets->createFolder($subfolder)) {
                    Craft::error('Failed to create subfolder: ' . $subfolderName, __METHOD__);
                    return null;
                }
            }

            $folderId = $subfolder->id;

        }

        $existingAsset = Asset::find()
            ->filename($filename)
            ->folderId($folderId)
            ->one();

        if ($existingAsset) {
            return $existingAsset;
        }

        // Download the file content
        $tempPath = AssetsHelper::tempFilePath(pathinfo($filename, PATHINFO_EXTENSION));
        file_put_contents($tempPath, file_get_contents($url));

        // Create the asset
        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->filename = $filename;
        $asset->newFolderId = $folderId;
        $asset->volumeId = $volume->id;
        $asset->title = pathinfo($filename, PATHINFO_FILENAME);

        // Save the asset
        if (!Craft::$app->elements->saveElement($asset)) {
            Craft::error('Failed to save the asset: ' . implode(', ', $asset->getErrorSummary(true)), __METHOD__);
            Plugin::log('Failed to save the asset: ' . implode(', ', $asset->getErrorSummary(true)));
            return null;
        }

        return $asset;

    }

}
