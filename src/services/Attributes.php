<?php

namespace bymayo\akeneo\services;

use bymayo\akeneo\Plugin;
use yii\base\Component;

class Attributes extends Component
{
    private const DEFAULT_LOCALE = 'en_GB';

    /**
     * Resolve the value of an Akeneo attribute based on its type.
     */
    public function resolveValue(
        string $attributeCode,
        string $attributeType,
        array $productValues,
        $client,
        string $locale = self::DEFAULT_LOCALE
    ): mixed {
        if (!array_key_exists($attributeCode, $productValues)) {
            return null;
        }

        $attributeData = $productValues[$attributeCode];

        // Filter to the correct locale entry
        $localeEntry = $this->findLocaleEntry($attributeData, $locale);

        if ($localeEntry === null) {
            return null;
        }

        $filteredData = [$localeEntry];

        return match ($attributeType) {
            'pim_catalog_simpleselect' => $this->resolveSimpleSelect($attributeCode, $filteredData, $client, $locale),
            'pim_catalog_multiselect' => $this->resolveMultiSelect($attributeCode, $filteredData, $client, $locale),
            'pim_catalog_boolean' => $this->resolveBoolean($filteredData),
            'pim_catalog_metric' => $this->resolveMetric($filteredData),
            'akeneo_reference_entity' => $this->resolveReferenceEntity($filteredData),
            'pim_catalog_asset_collection' => $this->resolveAssetCollection($filteredData),
            'pim_catalog_price_collection' => $this->resolvePriceCollection($filteredData),
            default => $this->resolveData($filteredData),
        };
    }

    /**
     * Find the best matching locale entry from an attribute's value array.
     */
    private function findLocaleEntry(array $attributeData, string $locale): ?array
    {
        // Try exact locale match
        foreach ($attributeData as $entry) {
            if (($entry['locale'] ?? null) === $locale) {
                return $entry;
            }
        }

        // Try null locale (non-localizable attribute)
        foreach ($attributeData as $entry) {
            if (($entry['locale'] ?? null) === null) {
                return $entry;
            }
        }

        // Fallback to first entry
        return $attributeData[0] ?? null;
    }

    /**
     * Simple select - fetch option label via API.
     */
    private function resolveSimpleSelect(string $attributeCode, array $attributeData, $client, string $locale): ?string
    {
        $optionCode = $attributeData[0]['data'] ?? null;

        if ($optionCode === null) {
            return null;
        }

        try {
            $option = $client->getAttributeOptionApi()->get($attributeCode, $optionCode);
            return $option['labels'][$locale] ?? $option['labels']['en_US'] ?? $optionCode;
        } catch (\Exception $e) {
            Plugin::log("Failed to resolve simpleselect '{$optionCode}' for '{$attributeCode}': " . $e->getMessage());
            return $optionCode;
        }
    }

    /**
     * Multi select - fetch labels for ALL options via API, comma-separated.
     */
    private function resolveMultiSelect(string $attributeCode, array $attributeData, $client, string $locale): ?string
    {
        $optionCodes = $attributeData[0]['data'] ?? [];

        if (empty($optionCodes) || !is_array($optionCodes)) {
            return null;
        }

        $labels = [];

        foreach ($optionCodes as $optionCode) {
            try {
                $option = $client->getAttributeOptionApi()->get($attributeCode, $optionCode);
                $labels[] = $option['labels'][$locale] ?? $option['labels']['en_US'] ?? $optionCode;
            } catch (\Exception $e) {
                Plugin::log("Failed to resolve multiselect '{$optionCode}' for '{$attributeCode}': " . $e->getMessage());
                $labels[] = $optionCode;
            }
        }

        return implode(', ', $labels);
    }

    /**
     * Boolean - converts to Yes/No string.
     */
    private function resolveBoolean(array $attributeData): string
    {
        $value = $attributeData[0]['data'] ?? null;
        return $value == 1 ? 'Yes' : 'No';
    }

    /**
     * Metric - extracts the numeric amount.
     */
    private function resolveMetric(array $attributeData): mixed
    {
        return $attributeData[0]['data']['amount'] ?? null;
    }

    /**
     * Reference entity - direct value with handle conversion.
     */
    private function resolveReferenceEntity(array $attributeData): ?string
    {
        $value = $attributeData[0]['data'] ?? null;

        if ($value === null) {
            return null;
        }

        return $this->convertHandle($value);
    }

    /**
     * Price collection - extracts the first price amount.
     */
    private function resolvePriceCollection(array $attributeData): mixed
    {
        $data = $attributeData[0]['data'] ?? null;

        if (is_array($data) && isset($data[0]['amount'])) {
            return (float) $data[0]['amount'];
        }

        return $data;
    }

    /**
     * Asset collection - returns raw data for further processing by caller.
     * The caller needs reference_data_name and data array to fetch assets.
     */
    private function resolveAssetCollection(array $attributeData): ?array
    {
        return $attributeData[0] ?? null;
    }

    /**
     * Direct data access (text, textarea, number, and fallback).
     */
    private function resolveData(array $attributeData): mixed
    {
        return $attributeData[0]['data'] ?? null;
    }

    private function convertHandle(string $handle): string
    {
        $string = str_replace('_', ' ', $handle);
        return ucwords($string);
    }
}
