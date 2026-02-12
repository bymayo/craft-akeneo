<?php

namespace bymayo\akeneo\services;

use bymayo\akeneo\Plugin;

use Craft;
use yii\base\Component;

use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;

use bymayo\akeneo\jobs\SyncProducts;
use bymayo\akeneo\models\Source;

use craft\elements\Entry;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\StringHelper;

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
        $settings = Plugin::getInstance()->getSettings();

        $searchFilters = $this->buildSearchFilters($source);

        $pageSize = $settings->syncPageSize;
        $iterationMax = $settings->syncMaxPages;

        $queryParams = !empty($searchFilters) ? ['search' => $searchFilters] : [];
        $currentPage = $this->client->getProductApi()->listPerPage($pageSize, true, $queryParams);

        $iterationCount = 0;

        do {
            $products = $currentPage->getItems();

            if ($iterationCount === 0) {
                Plugin::log("Syncing source '{$source->name}' - Total Products: " . $currentPage->getCount());
            }

            $job = new SyncProducts([
                'products' => $products,
                'sourceId' => $source->id,
                'batch' => $iterationCount + 1,
                'syncImages' => $syncImages,
            ]);

            Craft::$app->queue->push($job);

            $currentPage = $currentPage->getNextPage();
            $iterationCount++;

            if ($iterationCount >= $iterationMax) {
                break;
            }
        } while (null !== $currentPage);
    }

    private function buildSearchFilters(Source $source): array
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

    public function createEntryFromMappings(Source $source, array $data, bool $syncImages): bool
    {
        $mappings = Plugin::getInstance()->sources->getMappingsBySourceId($source->id);

        if (empty($mappings)) {
            Plugin::log("No field mappings found for source '{$source->name}' (ID: {$source->id})");
            return false;
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

        // Query existing entry or create new
        $section = Craft::$app->getEntries()->getSectionById($source->typeId);

        if (!$section) {
            Plugin::log("Section with ID {$source->typeId} not found for source '{$source->name}'");
            return false;
        }

        $entry = null;

        if ($identifierValue && $identifierHandle) {
            $query = Entry::find()
                ->sectionId($section->id)
                ->status(['live', 'pending', 'expired', 'disabled']);

            if ($identifierHandle === 'title') {
                $query->title($identifierValue);
            } elseif ($identifierHandle === 'slug') {
                $query->slug($identifierValue);
            } else {
                $query->{$identifierHandle}($identifierValue);
            }

            $entry = $query->one();
        }

        if (!$entry) {
            $entry = new Entry();
            $entry->sectionId = $section->id;
        }

        $entry->enabled = true;

        // Apply each field mapping
        foreach ($mappings as $mapping) {
            $handle = $mapping->craftFieldHandle;
            $akeneoAttr = $mapping->akeneoAttribute;

            // Check if JSON-encoded (table or matrix mapping)
            $decoded = json_decode($akeneoAttr, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                // Check if it's a table (sequential array) or matrix (associative object)
                if (array_is_list($decoded)) {
                    // Table field
                    $tableData = $this->resolveTableRows($decoded, $values, $attributeTypes, $locale);
                    $entry->setFieldValue($handle, $tableData);
                } else {
                    // Matrix field
                    $matrixData = $this->resolveMatrixMapping($decoded, $values, $attributeTypes, $syncImages, $locale);
                    $entry->setFieldValue($handle, $matrixData);
                }
                continue;
            }

            // Static value
            if (str_starts_with($akeneoAttr, 'static:')) {
                $value = substr($akeneoAttr, 7);
                $this->setEntryFieldValue($entry, $handle, $value);
                continue;
            }

            // Asset field
            $attrType = $attributeTypes[$akeneoAttr] ?? 'other';

            if ($attrType === 'pim_catalog_asset_collection') {
                if ($syncImages) {
                    $assetIds = $this->resolveAssetField($akeneoAttr, $values);
                    if (!empty($assetIds)) {
                        $entry->setFieldValue($handle, $assetIds);
                    }
                }
                continue;
            }

            // Regular Akeneo attribute
            $value = Plugin::getInstance()->attributes->resolveValue(
                $akeneoAttr, $attrType, $values, $this->client, $locale
            );

            if ($value !== null) {
                $this->setEntryFieldValue($entry, $handle, $value);
            }
        }

        // Save
        if (!Craft::$app->elements->saveElement($entry)) {
            $errors = implode(', ', $entry->getErrorSummary(true));
            Plugin::log("Failed to save entry for source '{$source->name}': {$errors}");
            Craft::error("Failed to save entry for source '{$source->name}': {$errors}", __METHOD__);
            return false;
        }

        return true;
    }

    private function setEntryFieldValue(Entry $entry, string $handle, mixed $value): void
    {
        if ($handle === 'title') {
            $entry->title = $value;
        } elseif ($handle === 'slug') {
            $entry->slug = $value;
        } else {
            $entry->setFieldValue($handle, $value);
        }
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

    private function resolveMatrixMapping(array $matrixData, array $values, array $attributeTypes, bool $syncImages, string $locale = 'en_GB'): array
    {
        $result = [];
        $blockIndex = 0;

        foreach ($matrixData as $entryTypeHandle => $entryRows) {
            foreach ($entryRows as $row) {
                $fields = [];

                foreach ($row as $fieldHandle => $fieldValue) {
                    // Nested table field (value is an array of table rows)
                    if (is_array($fieldValue)) {
                        $fields[$fieldHandle] = $this->resolveTableRows($fieldValue, $values, $attributeTypes, $locale);
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
                            $assetIds = $this->resolveAssetField($fieldValue, $values);
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

    private function resolveAssetField(string $akeneoCode, array $values): array
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
                    $asset = $this->createAsset($filename, $url, $subfolder);

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

    public function createAsset($filename, $url, $subfolderName = null)
    {

        // Get the volume folder
        $volume = Craft::$app->volumes->getVolumeByHandle('images');
        $rootFolder = Craft::$app->assets->getRootFolderByVolumeId($volume->id);

        // Create Akeneo folder
        $akeneoSubfolder = Craft::$app->assets->findFolder([
            'parentId' => $rootFolder->id,
            'name' => 'akeneo',
        ]);

        if (!$akeneoSubfolder) {

            $akeneoSubfolder = new \craft\models\VolumeFolder();
            $akeneoSubfolder->name = 'akeneo';
            $akeneoSubfolder->parentId = $rootFolder->id;
            $akeneoSubfolder->volumeId = $volume->id;
            $akeneoSubfolder->path = 'akeneo/';

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
