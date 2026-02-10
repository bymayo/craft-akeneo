<?php

namespace bymayo\akeneo\services;

use bymayo\akeneo\Plugin;

use Craft;
use yii\base\Component;

use Akeneo\Pim\ApiClient\AkeneoPimClientBuilder;
use Akeneo\Pim\ApiClient\Search\SearchBuilder;

use bymayo\akeneo\jobs\SyncProducts;

use craft\elements\Entry;
use craft\elements\Category;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\StringHelper;

use putyourlightson\blitz\Blitz;

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

    public function getCategories()
    {

        $categories = [];

        $currentPage = $this->client->getCategoryApi()->listPerPage(100, true);

        $iterationCount = 0;

        do {

            $currentPageCategories = $currentPage->getItems();

            // Plugin::log(print_r($currentPageCategories, true));

            $categories = array_merge($categories, $currentPageCategories);

            $currentPage = $currentPage->getNextPage();

        } while (null !== $currentPage);

        return $categories;

    }

    public function disableProducts()
    {

        $db = Craft::$app->getDb();
        $db->createCommand(
            'UPDATE {{%elements}} e
            INNER JOIN {{%entries}} ent ON e.id = ent.id
            INNER JOIN {{%sections}} s ON ent.sectionId = s.id
            SET e.enabled = 0
            WHERE s.id = 2 AND e.enabled = 1
            AND e.archived = 0 AND e.dateDeleted IS NULL'
        )->execute();

    }

    public function getProducts($syncImages = true)
    {

        Blitz::$plugin->settings->refreshCacheEnabled = false;

        $isDev = getenv('CRAFT_ENVIRONMENT') === 'dev' ? true : false;

        $searchBuilder = new SearchBuilder();
        $searchBuilder->addFilter('enabled', '=', true);
        $searchBuilder->addFilter('datapack', '=', true);
        $searchBuilder->addFilter('brands', 'IN', ['hudson_reed']);
        $searchBuilder->addFilter('sku', 'NOT EMPTY');
        $searchFilters = $searchBuilder->getFilters();

        // Plugin::log('Search Filters:' . print_r($searchFilters, true));

        $pageSize = $isDev ? 5 : 75;
        $iterationMax = $isDev ? 3 : 1000;
        $currentPage = $this->client->getProductApi()->listPerPage($pageSize, true, ['search' => $searchFilters]);

        $iterationCount = 0;

        $this->disableProducts();

        $categories = $this->getCategories();

        do {

            $products = $currentPage->getItems();

            if ($iterationCount === 0 && $isDev) {
                Plugin::log('Total Products: ' . $currentPage->getCount());
                Plugin::log('First Product Data:');
                Plugin::log(print_r($products[0], true));
                Plugin::log('-------------------------');
            }

            $this->createProductJob($products, $categories, $iterationCount, $syncImages);

            $currentPage = $currentPage->getNextPage();

            $iterationCount++;

            if ($iterationCount >= $iterationMax) {
                break;
            }

        } while (null !== $currentPage);

        Blitz::$plugin->settings->refreshCacheEnabled = true;

    }

    public function convertHandle($handle) {

        $string = str_replace('_', ' ', $handle);
        $output = ucwords($string);
        
        return $output;
    }

    public function createProductJob($products, $categories, $batch, $syncImages)
    {

        $job = new SyncProducts([
            'products' => $products,
            'categories' => $categories,
            'batch' => $batch + 1,
            'syncImages' => $syncImages
        ]);

        Craft::$app->queue->push($job);

        return;

    }

    public function createProductEntry($data, $categories, $syncImages = true)
    {

        $values = $data['values'];

        $sku = array_key_exists('sku', $values) ? $values['sku'][0]['data'] : $data['identifier'];

        $existingEntry = Entry::find()
            ->section('product')
            ->productCode($sku)
            ->status(['live', 'pending', 'expired', 'disabled'])
            ->one();

        $entry = new Entry();

        if ($existingEntry) {
            $entry = $existingEntry;
        }

        $range = array_key_exists('web_range_hr', $values) ? $this->client->getAttributeOptionApi()->get('web_range_hr', $values['web_range_hr'][0]['data'])['labels']['en_GB'] : '';

        $entry->sectionId = Craft::$app->entries->getSectionByHandle('product')->id;

        $entry->title = $values['title'][0]['data'];
        $entry->slug = $sku;
        $entry->enabled = true;
        
        $entry->setFieldValue('productCode', $sku);
        $entry->setFieldValue('description', $values['features'][0]['data']);
        $entry->setFieldValue('productPrice', (array_key_exists('rrp', $values) ? $values['rrp'][0]['data'][0]['amount'] : '')); // GBP Price
        $entry->setFieldValue('colour', array_key_exists('colour_marketing', $values) ? $this->client->getAttributeOptionApi()->get('colour_marketing', $values['colour_marketing'][0]['data'])['labels']['en_GB'] : '');
        $entry->setFieldValue('size', array_key_exists('product_width', $values) ? $values['product_width'][0]['data']['amount'] : '');

        $productList = [
            [
                'col1' => 'Height (mm)',
                'col2' => array_key_exists('product_height', $values) ? $values['product_height'][0]['data']['amount'] : ''
            ],
            [
                'col1' => 'Width (mm)',
                'col2' => array_key_exists('product_width', $values) ? $values['product_width'][0]['data']['amount'] : ''
            ],
            [
                'col1' => 'Depth (mm)',
                'col2' => array_key_exists('product_depth', $values) ? $values['product_depth'][0]['data']['amount'] : ''
            ],
            [
                'col1' => 'Colour/Finish',
                'col2' => array_key_exists('colour_marketing', $values) ? $this->client->getAttributeOptionApi()->get('colour_marketing', $values['colour_marketing'][0]['data'])['labels']['en_GB'] : ''
            ],
            [
                'col1' => 'Guarantee',
                'col2' => $this->convertHandle(array_key_exists('guarantee', $values) ? $values['guarantee'][0]['data'] : '')
            ]
        ];

        $entry->setFieldValues(['productList' => $productList]);

        $productDataGroups = [
            'productDimensions' => [
                'type' => 'itemProductData',
                'heading' => 'Product Dimensions',
                'data' => [
                    'product_weight' => 'Net Weight (kg)',
                    'product_height' => 'Height (mm)',
                    'product_width' => 'Width (mm)',
                    'product_depth' => 'Depth (mm)',
                    'product_length' => 'Length (mm)',
                    'material' => 'Product Material',
                    'colour_marketing' => 'Colour/Finish'
                ]
            ],
            'productDetails' => [
                'type' => 'itemProductData',
                'heading' => 'Product Details',
                'data' => [
                    'fixings_inc' => 'Fixings Included',
                    'assembly' => 'Assembly Required',
                    'furniture_basin_type' => 'Basin Type',
                    'fascia_finish' => 'Fascia Material Finish',
                    'cab_mat_thickness' => 'Cabinet Thickness',
                    'fascia_thickness' => 'Fascia Thickness',
                    'construction_method' => 'Construction Method',
                    'construction_type' => 'Construction Type',
                    'legs_included' => 'Adjustable Legs Supplied',
                    'handle_inc' => 'Handle Included',
                    'handle_type' => 'Handle Type',
                    'handle_finish' => 'Handle Finish',
                    'hinge_type' => 'Hinge Type',
                    'mount_type' => 'Mount Type',
                    'no_drawers' => 'Number of Drawers',
                    'no_shelves' => 'Number of Shelves',
                    'no_doors' => 'Number of Doors',
                    'waste_shroud' => 'Waste Shroud',
                    'fsc_certified' => 'FSC Certified Materials',
                    'no_tapholes' => 'Basin Tap Holes',
                    'overflow_cover_inc' => 'Basin Overflow Cover',
                    'chainstay_inc' => 'Basin Chain Stay',
                    'basin_sink_template' => 'Basin Template',
                    'basin_waste_type_req' => 'Basin Waste Type Required',
                    'overflow_inc' => 'Includes Overflow?',
                    'bowl_shape' => 'Bowl Shape',
                    'bottle_trap_inc' => 'Bottle Trap Included',
                    'no_bowls' => 'Number of Bowls',
                    'Backsplash_inc' => 'Backsplash Included',
                    'inner_bowl_height' => 'Inner Bowl Height',
                    'inner_bowl_width' => 'Inner Bowl Width',
                    'inner_bowl_depth' => 'Inner Bowl Depth',
                    'max_projection' => 'Projection',
                    'pan_trap_style' => 'Pan Trap Style',
                    'seat_function' => 'Seat Function',
                    'toilet_seat_type' => 'Seat Type',
                    'seat_hinge_type' => 'Seat Hinge Type',
                    'pan_type' => 'Toilet Pan Type',
                    'seat_hinge_mat' => 'Hinge Material',
                    'hinge_centres_min' => 'Hinge Centres (min)',
                    'hinge_centres_max' => 'Hinge Centres (max)',
                    'displaced_capacity' => 'Displaced Capacity',
                    'bath_mat_thickness' => 'Material Thickness',
                    'drilled_for_taps' => 'Drilled for Taps',
                    'includes_waste' => 'Includes Waste',
                    'embossed_base' => 'Embossed Base',
                    'bath_volume' => 'Volume',
                    'bath_shape' => 'Bath Shape',
                    'bath_type' => 'Bath Type',
                    'waste_position' => 'Bath Waste Position',
                    'seat_inc' => 'Bath Seat Included',
                    'bath_panel_inc' => 'Bath Panel Included',
                    'support_bar_inc' => 'Support Bar Included',
                    'handle_mat' => 'Handle Material',
                    'easy_clean' => 'Easy Clean',
                    'easy_fit' => 'Easy Fit',
                    'radius' => 'Radius',
                    'glass_thickness' => 'Glass Thickness',
                    'wheel_type' => 'Wheel Type',
                    'enclosure_design' => 'Enclosure Design',
                    'adjustment_min' => 'Enclosure Adjust (min)',
                    'adjustment_max' => 'Enclosure Adjust (max)',
                    'entry_width' => 'Entry Width',
                    'enclosure_shape' => 'Enclosure Shape',
                    'door_type' => 'Door Type',
                    'tray_inc' => 'Tray Included',
                    'glass_design' => 'Glass Design',
                    'waste_size_req' => 'Waste Required',
                    'tray_leg_compatible' => 'Compatible with leg kit',
                    'waste_plate_inc' => 'Waste Plate Included',
                    'tray_raised_edge' => 'Raised Edge',
                    'slip_resistant' => 'Slip Resistant',
                    'waste_type' => 'Waste Type',
                    'control_type' => 'Control Type',
                    'flow_rate_01' => 'Flow Rate 0.1 Bar',
                    'flow_rate_05' => 'Flow Rate 0.5 Bar',
                    'flow_rate_1' => 'Flow Rate 1 Bar',
                    'flow_rate_2' => 'Flow Rate 2 Bar',
                    'flow_rate_3' => 'Flow Rate 3 Bar',
                    'wras_approved' => 'WRAS Approved',
                    'wras_approval_no' => 'WRAS Approval Number',
                    'tmv2_approved' => 'TMV2 Approved',
                    'tmv3_approved' => 'TMV3 Approved',
                    'tmv2_approval_no' => 'TMV2 Approval Number',
                    'tmv3_approval_no' => 'TMV3 Approval Number',
                    'tap_holes_req' => 'Tap Installation Holes',
                    'no_handles' => 'No. of Handles',
                    'tap_spout_height' => 'Height of Spout',
                    'tap_spout_projection' => 'Tap Spout Projection',
                    'cartridge_inc' => 'Cartridge Included',
                    'cartridge_mat' => 'Cartridge material',
                    'shower_handset_inc' => 'Handset Included',
                    'mounting_hardware_inc' => 'Mounting Hardware Included',
                    'tap_tails_inc' => 'Tap Tails Included',
                    'valve_cartridge_type' => 'Valve/Cartridge',
                    'pipe_size' => 'Pipe Size',
                    'pipe_centres' => 'Pipe Centres',
                    'min_operating_pressure' => 'Operation Pressure',
                    'shower_head_inc' => 'Head Included',
                    'no_body_jets' => 'Number of Body Jets',
                    'outlet_elbow_inc' => 'Outlet Elbow Included',
                    'flow_rate_02' => 'Flow Rate 0.2 Bar',
                    'shower_head_shape' => 'Shower Head Shape',
                    'valve_included' => 'Valve Included',
                    'handle_shape' => 'Valve Handle Type/Shape',
                    'valve_mount' => 'Valve Mount Type',
                    'shower_valve_shape' => 'Shower Valve Style/Shape',
                    'stain_resistant' => 'Stain/Rust Resistant',
                    'no_outlets' => 'No. of Outlets',
                    'wall_centre_tappings' => 'Wall to Centre of Tappings',
                    'ral_number' => 'RAL Number',
                    'fuel_type' => 'Fuel Type',
                    'no_heat_settings' => 'Number of Heating Settings',
                    'no_panels' => 'Number of Sections/Panels',
                    'brackets_inc' => 'Brackets Included',
                    'btu_30' => 'BTU 30C',
                    'thermal_output_30' => 'Thermal Output 30C',
                    'btu_50' => 'BTU 50C',
                    'thermal_output_50' => 'Thermal Output 50C',
                    'btu_60' => 'BTU 60C',
                    'thermal_output_60' => 'Thermal Output 60C',
                    'heating_element_watt' => 'Element Wattage',
                    'bulb_type' => 'Bulb Type',
                    'power_source' => 'Power Source',
                    'illuminated' => 'Illuminated',
                    'mirror_features' => 'Product Features',
                    'no_lights' => 'Number of Lights',
                    'bulb_wattage' => 'Bulb Wattage',
                    'voltage' => 'Bulb Voltage',
                    'ce_certified' => 'CE & UKCA',
                    'weee_cert' => 'WEEE Certification',
                    'emc_cert' => 'EMC Certification',
                    'lvd_certification' => 'LVD Certification',
                    'mirror_shape' => 'Mirror Shape',
                    'energy_efficient' => 'Energy Efficient',
                    'waste_shape' => 'Waste Shape',
                    'waste_pipe_length' => 'Waste Pipe Length',
                    'waste_pipe_dia' => 'Waste Pipe Diameter',
                    'product_dia' => 'Product Diameter'
                ]
            ]
        ];

        $productData = [];

        foreach ($productDataGroups as $index => $group) {

            $tableBody = [];

            foreach ($group['data'] as $key => $value) {

                $col2 = '';

                if (array_key_exists($key, $values)) {

                    $attributeType = $values[$key][0]['attribute_type'];

                    // Plugin::log('Key: ' . $key);
                    // Plugin::log('Value: ' . print_r($values[$key][0], true));
                    // Plugin::log('Attribute Type: ' . $attributeType);

                    if ($attributeType == 'pim_catalog_simpleselect') {
                        $col2 = $this->client->getAttributeOptionApi()->get($key, $values[$key][0]['data'])['labels']['en_GB'];
                    }
                    else if ($attributeType == 'pim_catalog_multiselect') {
                        $col2 = $this->client->getAttributeOptionApi()->get($key, $values[$key][0]['data'][0])['labels']['en_GB'];
                    }
                    else if ($attributeType == 'pim_catalog_boolean') {
                        $col2 = $values[$key][0]['data'] == 1 ? 'Yes' : 'No';
                    }
                    else if ($attributeType == 'pim_catalog_metric') {
                        $col2 = $values[$key][0]['data']['amount'];
                    }
                    else if ($attributeType == 'akeneo_reference_entity') {
                        $col2 = $this->convertHandle(array_key_exists($key, $values) ? $values[$key][0]['data'] : '');
                    }
                    else {
                        $col2 = $values[$key][0]['data'];
                    }
                }

                if ($col2) {

                    $tableBody[] = [
                        'col1' => $value,
                        'col2' => $col2
                    ];

                }
            }

            // Plugin::log('Table Body: ' . print_r($tableBody, true));

            $productData['new' . $index] = [
                'type' => $group['type'],
                'enabled' => true,
                'fields' => [
                    'tableHead' => $group['heading'],
                    'tableBody' => $tableBody
                ]
            ];

        }

        $entry->setFieldValues(['productData' => $productData]);

        // Create images and assets 

        if ($syncImages)
        {

            $images = [];

            $imagesArray = [
                'cutout_1' => 'cutout_1_link',
                'lifestyle_1' => 'lifestyle_1_link',
                'line_drawing' => 'line_drawing_link'
            ];

            foreach ($imagesArray as $key => $urlHandle) {
                $asset = $this->createImage($values, $key, $urlHandle);
                if ($asset) {
                    $images[$key] = $asset->id;
                }
            }

            // Plugin::log('Images: ' . print_r($images, true));

            $entry->setFieldValue('productSlideshow', $images);

            if (count($images) > 0) {
                
                if (array_key_exists('cutout_imagery', $images)) {
                    $entry->setFieldValue('thumbnailImage', [$images['cutout_imagery']]);
                } else {
                    // Set thumbnail image to any image if cutout image isnt found
                    $entry->setFieldValue('thumbnailImage', [$images[0]]);
                }

                if (array_key_exists('lifestyle_image', $images)) {
                    $entry->setFieldValue('thumbnailImageLifestyle', [$images['lifestyle_image']]);
                }
                else {
                    // Clear lifestyle image if previously set
                    $entry->setFieldValue('thumbnailImageLifestyle', null);
                }
            }

        }

        // Collection

        $collection = $this->getCollection($range);
        $entry->setFieldValues(['entriesCollection' => [$collection]]);

        // Categories - Product

        $categoriesProduct = [];

        $categoryProduct = array_key_exists('web_category_hr', $values) ? $this->client->getAttributeOptionApi()->get('web_category_hr', $values['web_category_hr'][0]['data'])['labels']['en_GB'] : '';

        $categoriesProduct[] = $this->getCategory($categoryProduct, 'product', 'Hudson Reed', false);
            
        // if ($categoryProduct) {

        //     $brand = $values['brands'][0]['data'];

        //     if (in_array('brand_old_london', $brand)) {
        //         $categoriesProduct[] = $this->getCategory($categoryProduct, 'product', 'Old London', false);
        //     }
        //     else if (in_array('brand_hudson_reed', $brand)) {
        //         $categoriesProduct[] = $this->getCategory($categoryProduct, 'product', 'Hudson Reed', false);
        //     }
            
        // }

        $entry->setFieldValues(['categoriesProduct' => $categoriesProduct]);

        // Categories - Facets

        $categoriesProductFacets = [];

        if (array_key_exists('colour_marketing', $values)) {
            $colour = $this->client->getAttributeOptionApi()->get('colour_marketing', $values['colour_marketing'][0]['data'])['labels']['en_GB'];
            $categoriesProductFacets[] = $this->getCategory($colour, 'productFacets', 'Colour');
        }

        if (array_key_exists('mount_type', $values)) {
            $mountType = $this->client->getAttributeOptionApi()->get('mount_type', $values['mount_type'][0]['data'][0])['labels']['en_GB'];
            $categoriesProductFacets[] = $this->getCategory($mountType, 'productFacets', 'Mount Type');
        }

        if (array_key_exists('glass_thickness', $values)) {
            $width = $values['glass_thickness'][0]['data']['amount'];
            $categoriesProductFacets[] = $this->getCategory($width, 'productFacets', 'Glass Thickness');
        }

        if (array_key_exists('enclosure_shape', $values)) {
            $enclosureShape = $this->client->getAttributeOptionApi()->get('enclosure_shape', $values['enclosure_shape'][0]['data'])['labels']['en_GB'];
            $categoriesProductFacets[] = $this->getCategory($enclosureShape, 'productFacets', 'Enclosure Shape');
        }

        if (array_key_exists('no_outlets', $values)) {
            $noOutlets = $values['no_outlets'][0]['data'];
            $categoriesProductFacets[] = $this->getCategory($noOutlets, 'productFacets', 'Number of Outlets');
        }

        if (array_key_exists('tap_holes_req', $values)) {
            $tapHolesReq = $values['tap_holes_req'][0]['data'];
            $categoriesProductFacets[] = $this->getCategory($tapHolesReq, 'productFacets', 'Tap Holes Required');
        }

        if (array_key_exists('frame_finish', $values)) {
            $frameFinish = $this->client->getAttributeOptionApi()->get('frame_finish', $values['frame_finish'][0]['data'])['labels']['en_GB'];
            $categoriesProductFacets[] = $this->getCategory($frameFinish, 'productFacets', 'Frame Finish');
        }

        if (array_key_exists('mirror_features', $values)) {
            $mirrorFeatures = $this->client->getAttributeOptionApi()->get('mirror_features', $values['mirror_features'][0]['data'][0])['labels']['en_GB'];
            $categoriesProductFacets[] = $this->getCategory($mirrorFeatures, 'productFacets', 'Mirror Features');
        }

        if (array_key_exists('fuel_type', $values)) {
            $fuelType = $this->client->getAttributeOptionApi()->get('fuel_type', $values['fuel_type'][0]['data'])['labels']['en_GB'];
            $categoriesProductFacets[] = $this->getCategory($fuelType, 'productFacets', 'Fuel Type');
        }

        if (array_key_exists('radiator_type', $values)) {
            $radiatorType = $this->client->getAttributeOptionApi()->get('radiator_type', $values['radiator_type'][0]['data'])['labels']['en_GB'];
            $categoriesProductFacets[] = $this->getCategory($radiatorType, 'productFacets', 'Radiator Type');
        }

        $entry->setFieldValues(['categoriesProductFacets' => $categoriesProductFacets]);

        // Save
        
        if (!Craft::$app->elements->saveElement($entry)) {
            Craft::error('Failed to save the product entry: ' . implode(', ', $entry->getErrorSummary(true)), __METHOD__);
            return false;
        }

        return true;

    }

    public function createImage($data, $key, $urlHandle)
    {

        $image = array_key_exists($key, $data) ? $data[$key][0] : null;

        if ($image) {

            $imageData = $this->client->getAssetManagerApi()->get($image['reference_data_name'], $image['data'][0]);

            if (array_key_exists($urlHandle, $imageData['values'])) {
            
                $imageUrl = $imageData['values'][$urlHandle][0]['data'];

                $asset = $this->createAsset(basename(parse_url($imageUrl, PHP_URL_PATH)), $imageUrl, $urlHandle);

                if ($asset) {
                    return $asset;
                }

            }
        }

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

    public function getCollection($title)
    {

        if ($title)
        {

            $collectionEntry = Entry::find()
                ->section('collection')
                ->title($title)
                ->status(['live', 'pending', 'expired', 'disabled'])
                ->one();

            if (!$collectionEntry) {

                $collectionEntry = new Entry();
                $collectionEntry->sectionId = Craft::$app->entries->getSectionByHandle('collection')->id;
                $collectionEntry->title = $title;
                $collectionEntry->slug = StringHelper::toKebabCase($title);
                $collectionEntry->enabled = false; 

                if (!Craft::$app->elements->saveElement($collectionEntry)) {
                    Craft::error('Failed to save the collection entry: ' . implode(', ', $collectionEntry->getErrorSummary(true)), __METHOD__);
                    return null;
                }

            }

            if ($collectionEntry) {
                return $collectionEntry->id;
            }

        }

        return null;

    }

    public function getCategory($title, $group, $parent = null, $enabled = true)
    {

        if ($title)
        {

            $groupId = Craft::$app->categories->getGroupByHandle($group)->id;
            $slug = StringHelper::toKebabCase($title);

            $parentCategory = $parent ? $this->getCategory($parent, $group) : null;

            $category = Category::find()
                ->groupId($groupId)
                ->title($title)
                ->status(null)
                ->descendantOf($parentCategory ? $parentCategory : null)
                ->one();

            if (!$category) {

                $category = new Category();
                $category->groupId = $groupId;
                $category->title = $title;
                $category->slug = $slug;
                $category->enabled = $enabled;

                if ($parentCategory) {
                    $category->parentId = $parentCategory;
                }

                if (!Craft::$app->elements->saveElement($category)) {
                    Craft::error('Failed to save the category entry: ' . implode(', ', $category->getErrorSummary(true)), __METHOD__);
                    return null;
                }

            }

            if ($category) {
                return $category->id;
            }

        }

        return null;

    }

}
