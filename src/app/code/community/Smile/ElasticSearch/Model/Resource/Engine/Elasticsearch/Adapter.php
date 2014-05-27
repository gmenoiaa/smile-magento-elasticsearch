<?php
/**
 * Elastic search client implementation.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Searchandising Suite to newer
 * versions in the future.
 *
 * This work is a fork of Johann Reinke <johann@bubblecode.net> previous module
 * available at https://github.com/jreinke/magento-elasticsearch
 *
 * @category  Smile
 * @package   Smile_ElasticSearch
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2013 Smile
 * @license   Apache License Version 2.0
 */

// Include the Elasticsearch required libraries used by the adapter
require_once 'vendor/autoload.php';

/**
 * Implementation of the ElasticSearch adapter
 *
 * @category  Smile
 * @package   Smile_ElasticSearch
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2013 Smile
 * @license   Apache License Version 2.0
 */
class Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter
{
    /**
     * @var Varien_Object
     */
    protected $_config;

    /**
     * @var Elasticsearch\Client
     */
    protected $_client = null;

    /**
     * @var string
     */
    protected $_currentIndexName = null;

    /**
     * @var bool
     */
    protected $_indexNeedInstall = false;

    /**
     * @var string Date format.
     * @link http://www.elasticsearch.org/guide/reference/mapping/date-format.html
     */
    protected $_dateFormat = 'date';

    /**
     * @var array Stop languages for token filter.
     * @link http://www.elasticsearch.org/guide/reference/index-modules/analysis/stop-tokenfilter.html
     */
    protected $_stopLanguages = array(
            'arabic', 'armenian', 'basque', 'brazilian', 'bulgarian', 'catalan', 'czech',
            'danish', 'dutch', 'english', 'finnish', 'french', 'galician', 'german', 'greek',
            'hindi', 'hungarian', 'indonesian', 'italian', 'norwegian', 'persian', 'portuguese',
            'romanian', 'russian', 'spanish', 'swedish', 'turkish',
    );

    /**
     * @var array Snowball languages.
     * @link http://www.elasticsearch.org/guide/reference/index-modules/analysis/snowball-tokenfilter.html
    */
    protected $_snowballLanguages = array(
            'Armenian', 'Basque', 'Catalan', 'Danish', 'Dutch', 'English', 'Finnish', 'French',
            'German', 'Hungarian', 'Italian', 'Kp', 'Lovins', 'Norwegian', 'Porter', 'Portuguese',
            'Romanian', 'Russian', 'Spanish', 'Swedish', 'Turkish',
    );


    /**
     * Initializes search engine config and index name.
     *
     * @param array|bool $params Client init params.
     */
    public function __construct($params = false)
    {
        $config = $this->_getHelper()->getEngineConfigData();

        $this->_config = new Varien_Object($config);

        $this->_client = new \Elasticsearch\Client(
            array(
                'hosts'   => $config['hosts'],
                'logging' => false
            )
        );
        //parent::__construct($config);
        if (!isset($config['alias'])) {
            Mage::throwException('Alias must be defined for search engine client.');
        }
        $this->_currentIndexName = $config['alias'];
    }

    /**
     * Indicates if connection to the search engine is up or not
     *
     * @return bool
     */
    public function getStatus()
    {
        return $this->_client->ping();
    }

    /**
     * Deletes index.
     *
     * @return bool
     */
    public function deleteIndex()
    {
        $indices = $this->_client->indices();
        $params  = array('index' => $this->_currentIndexName);
        if ($indices->exists($params)) {
            $indices->delete($params);
        }
        return true;
    }

    /**
     * Refreshes index
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter
     */
    public function refreshIndex()
    {
        $indices = $this->_client->indices();
        $params  = array('index' => $this->_currentIndexName);
        if ($indices->exists($params)) {
            $indices->refresh($params);
        }
        return $this;
    }

    /**
     * Prepare a new index for full reindex
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter Self Reference
     */
    public function prepareNewIndex()
    {
        // Current date use to compute the index name
        $currentDate = new Zend_Date();

        // Default pattern if nothing set into the config
        $pattern = '{{YYYYMMDD}}-{{HHmmss}}';

        // Try to get the pattern from config
        $config = $this->_getHelper()->getEngineConfigData();
        if (isset($config['indices_pattern'])) {
            $pattern = $config['indices_pattern'];
        }

        // Parse pattern to extract datetime tokens
        $matches = array();
        preg_match_all('/{{([\w]*)}}/', $pattern, $matches);

        foreach (array_combine($matches[0], $matches[1]) as $k => $v) {
            // Replace tokens (UTC date used)
            $pattern = str_replace($k, $currentDate->toString($v), $pattern);
        }

        $indexName = $config['alias'] . '-' . $pattern;

        // Set the new index name
        $this->_currentIndexName = $indexName;

        // Indicates an old index exits
        $this->_indexNeedInstall = true;
        $this->_prepareIndex();

        return $this;
    }

    /**
     * Create document to index.
     *
     * @param string $id   Document Id
     * @param array  $data Data indexed
     * @param string $type Document type
     *
     * @return string Json representation of the bulk document
     */
    public function createDoc($id = '', array $data = array(), $type = 'product')
    {
        $headerRow = json_encode(
            array(
                'index' => array(
                   '_index' => $this->_currentIndexName,
                   '_type'  => $type,
                   '_id'    => $id
                )
            )
        );
        $dataRow = json_encode($data);
        $result = array($headerRow, $dataRow);
        return implode("\n", $result);
    }

    /**
     * Bulk document insert
     *
     * @param array $docs Document prepared with createDoc methods
     *
     * @return  Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter Self reference
     */
    public function addDocuments(array $docs)
    {
        if (!empty($docs)) {
            $docs[] = '';
            $bulkParams = array('body' => implode("\n", $docs));
            $ret = $this->_client->bulk($bulkParams);
        }
        return $this;
    }

    /**
     * Install the new index after full reindex
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter
     */
    public function installNewIndex()
    {
        if ($this->_indexNeedInstall) {
            $indices = $this->_client->indices();
            $alias = $this->getConfig('alias');
            $indices->putAlias(array('index' => $this->_currentIndexName, 'name' => $alias));
            $allIndices = $indices->getMapping(array('index'=> $alias));
            foreach (array_keys($allIndices) as $index) {
                if ($index != $this->_currentIndexName) {
                    $indices->delete(array('index' => $index));
                }
            }
        }

        return $this;
    }

    /**
     * Checks if ICU folding is enabled.
     *
     * @link http://www.elasticsearch.org/guide/reference/index-modules/analysis/icu-plugin.html
     * @return bool
     */
    public function isIcuFoldingEnabled()
    {
        return (bool) $this->getConfig('enable_icu_folding');
    }

    /**
     * Read configuration from key
     *
     * @param string $key Name of the config param to retrieve
     *
     * @return mixed
     */
    public function getConfig($key)
    {
        return $this->_config->getData($key);
    }

    /**
     * Returns attribute type for indexation.
     *
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute Attribute
     *
     * @return string
     */
    protected function _getAttributeType($attribute)
    {
        $type = 'string';
        if ($attribute->getBackendType() == 'decimal') {
            $type = 'double';
        } elseif ($attribute->getSourceModel() == 'eav/entity_attribute_source_boolean') {
            $type = 'boolean';
        } elseif ($attribute->getBackendType() == 'datetime') {
            $type = 'date';
        } elseif ($attribute->usesSource() || $attribute->getFrontendClass() == 'validate-digits') {
            $type = 'string';
        }

        return $type;
    }

    /**
     * Builds index properties for indexation according to available attributes and stores.
     *
     * @return array
     */
    public function getIndexProperties()
    {
        $cacheId = Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch::CACHE_INDEX_PROPERTIES_ID;
        if ($properties = Mage::app()->loadCache($cacheId)) {
            return unserialize($properties);
        }

        /** @var $helper Smile_ElasticSearch_Helper_Data */
        $helper = $this->_getHelper();
        $indexSettings = $this->_getIndexSettings();
        $properties = array();

        $attributes = $helper->getSearchableAttributes(array('varchar', 'int'));
        foreach ($attributes as $attribute) {
            /** @var $attribute Mage_Catalog_Model_Resource_Eav_Attribute */
            if ($this->_isAttributeIndexable($attribute)) {
                foreach (Mage::app()->getStores() as $store) {
                    /** @var $store Mage_Core_Model_Store */
                    $locale = $helper->getLocaleCode($store);
                    $key = $helper->getAttributeFieldName($attribute, $locale);
                    $type = $this->_getAttributeType($attribute);
                    if ($type !== 'string') {
                        $properties[$key] = array(
                                'type' => $type,
                        );
                    } else {
                        $weight = $attribute->getSearchWeight();
                        $properties[$key] = array(
                                'type' => 'multi_field',
                                'fields' => array(
                                        $key => array(
                                                'type' => $type,
                                                'boost' => $weight > 0 ? $weight : 1,
                                        ),
                                        'untouched' => array(
                                                'type' => $type,
                                                'index' => 'not_analyzed',
                                        ),
                                ),
                        );
                        foreach (array_keys($indexSettings['analysis']['analyzer']) as $analyzer) {
                            $properties[$key]['fields'][$analyzer] = array(
                                    'type' => 'string',
                                    'analyzer' => $analyzer,
                                    'boost' => $attribute->getSearchWeight(),
                            );
                        }
                    }
                }
            }
        }

        $attributes = $helper->getSearchableAttributes('text');
        foreach ($attributes as $attribute) {
            /** @var $attribute Mage_Catalog_Model_Resource_Eav_Attribute */
            foreach (Mage::app()->getStores() as $store) {
                /** @var $store Mage_Core_Model_Store */
                $languageCode = $helper->getLanguageCodeByStore($store);
                $locale = $helper->getLocaleCode($store);
                $key = $helper->getAttributeFieldName($attribute, $locale);
                $weight = $attribute->getSearchWeight();
                $properties[$key] = array(
                        'type' => 'string',
                        'boost' => $weight > 0 ? $weight : 1,
                        'analyzer' => 'analyzer_' . $languageCode,
                );
            }
        }

        $attributes = $helper->getSearchableAttributes(array('static', 'varchar', 'decimal', 'datetime'));
        foreach ($attributes as $attribute) {
            /** @var $attribute Mage_Catalog_Model_Resource_Eav_Attribute */
            $key = $helper->getAttributeFieldName($attribute);
            if ($this->_isAttributeIndexable($attribute) && !isset($properties[$key])) {
                $weight = $attribute->getSearchWeight();
                $properties[$key] = array(
                        'type' => $this->_getAttributeType($attribute),
                        'boost' => $weight > 0 ? $weight : 1,
                );
                if ($attribute->getBackendType() == 'datetime') {
                    $properties[$key]['format'] = $this->_dateFormat;
                }
            }
        }

        // Handle sortable attributes
        $attributes = $helper->getSortableAttributes();
        foreach ($attributes as $attribute) {
            /** @var $attribute Mage_Catalog_Model_Resource_Eav_Attribute */
            $type = 'string';
            if ($attribute->getBackendType() == 'decimal') {
                $type = 'double';
            } elseif ($attribute->getBackendType() == 'datetime') {
                $type = 'date';
                $format = $this->_dateFormat;
            }
            foreach (Mage::app()->getStores() as $store) {
                /** @var $store Mage_Core_Model_Store */
                $locale = $helper->getLocaleCode($store);
                $key = $helper->getSortableAttributeFieldName($attribute, $locale);
                if (!array_key_exists($key, $properties)) {
                    $properties[$key] = array(
                            'type' => $type,
                            'index' => 'not_analyzed',
                    );
                    if (isset($format)) {
                        $properties[$key]['format'] = $format;
                    }
                }
            }
        }

        // Custom attributes indexation
        $properties['visibility'] = array('type' => 'integer');
        $properties['store_id']   = array('type' => 'integer');
        $properties['in_stock']   = array('type' => 'boolean');

        if (Mage::app()->useCache('config')) {
            $lifetime = $this->_getHelper()->getCacheLifetime();
            Mage::app()->saveCache(serialize($properties), $cacheId, array('config'), $lifetime);
        }

        foreach (Mage::app()->getStores() as $store) {
            $languageCode = $helper->getLanguageCodeByStore($store);
            $properties[$helper->getSuggestFieldName($store)] = array(
                'type'     => 'completion',
                'payloads' => true,
                'max_input_length' => 500,
                'index_analyzer' => 'analyzer_' . $languageCode,
                'search_analyzer' => 'analyzer_' . $languageCode,
                'preserve_separators' => false
            );
        }

        return $properties;
    }


    /**
     * Returns indexation analyzers and filters configuration.
     *
     * @return array
     */
    protected function _getIndexSettings()
    {
        $indexSettings = array();
        $indexSettings['number_of_replicas'] = (int) $this->getConfig('number_of_replicas');
        $indexSettings['analysis']['analyzer'] = array(
            'whitespace' => array(
                'tokenizer' => 'standard',
                'filter' => array('lowercase'),
            ),
            'edge_ngram_front' => array(
                'tokenizer' => 'standard',
                'filter' => array('length', 'edge_ngram_front', 'lowercase'),
            ),
            'edge_ngram_back' => array(
                'tokenizer' => 'standard',
                'filter' => array('length', 'edge_ngram_back', 'lowercase'),
            ),
            'shingle' => array(
                'tokenizer' => 'standard',
                'filter' => array('shingle', 'length', 'lowercase'),
            ),
            'shingle_strip_ws' => array(
                'tokenizer' => 'standard',
                'filter' => array('shingle', 'strip_whitespaces', 'length', 'lowercase'),
            ),
            'shingle_strip_apos_and_ws' => array(
                'tokenizer' => 'standard',
                'filter' => array('shingle', 'strip_apostrophes', 'strip_whitespaces', 'length', 'lowercase'),
            ),
        );
        $indexSettings['analysis']['filter'] = array(
            'shingle' => array(
                'type' => 'shingle',
                'max_shingle_size' => 20,
                'output_unigrams' => true,
            ),
            'strip_whitespaces' => array(
                'type' => 'pattern_replace',
                'pattern' => '\s',
                'replacement' => '',
            ),
            'strip_apostrophes' => array(
                'type' => 'pattern_replace',
                'pattern' => "'",
                'replacement' => '',
            ),
            'edge_ngram_front' => array(
                'type' => 'edgeNGram',
                'min_gram' => 3,
                'max_gram' => 10,
                'side' => 'front',
            ),
            'edge_ngram_back' => array(
                'type' => 'edgeNGram',
                'min_gram' => 3,
                'max_gram' => 10,
                'side' => 'back',
            ),
            'length' => array(
                'type' => 'length',
                'min' => 2,
            ),
        );
        /** @var $helper Smile_ElasticSearch_Helper_Data */
        $helper = $this->_getHelper();
        foreach (Mage::app()->getStores() as $store) {
            /** @var $store Mage_Core_Model_Store */
            $languageCode = $helper->getLanguageCodeByStore($store);
            $lang = Zend_Locale_Data::getContent('en_GB', 'language', $helper->getLanguageCodeByStore($store));
            if (!in_array($lang, $this->_snowballLanguages)) {
                continue; // language not present by default in elasticsearch
            }
            $indexSettings['analysis']['analyzer']['analyzer_' . $languageCode] = array(
                    'type' => 'custom',
                    'tokenizer' => 'standard',
                    'filter' => array('length', 'lowercase', 'snowball_' . $languageCode),
            );
            $indexSettings['analysis']['filter']['snowball_' . $languageCode] = array(
                    'type' => 'snowball',
                    'language' => $lang,
            );
        }

        if ($this->isIcuFoldingEnabled()) {
            foreach ($indexSettings['analysis']['analyzer'] as &$analyzer) {
                array_unshift($analyzer['filter'], 'icu_folding');
            }
            unset($analyzer);
        }

        return $indexSettings;
    }

    /**
     * Retrieves searchable fields according to text query.
     *
     * @param bool   $onlyFuzzy Return only field used for fuzzy matching
     * @param string $q         Search query
     *
     * @return array
     */
    public function getSearchFields($onlyFuzzy = false, $q = '')
    {
        $properties = $this->getIndexProperties();
        $fields = array();
        foreach ($properties as $key => $property) {
            if ($property['type'] == 'date'
                    || ($property['type'] == 'multi_field' && $property['fields'][$key]['type'] == 'date')) {
                continue;
            }
            if (!is_bool($q)
            && ($property['type'] == 'boolean'
                    || ($property['type'] == 'multi_field' && $property['fields'][$key]['type'] == 'boolean'))) {
                continue;
            }
            if (!is_integer($q)
            && ($property['type'] == 'integer'
                    || ($property['type'] == 'multi_field' && $property['fields'][$key]['type'] == 'integer'))) {
                continue;
            }
            if (!is_double($q)
            && ($property['type'] == 'double'
                    || ($property['type'] == 'multi_field' && $property['fields'][$key]['type'] == 'double'))) {
                continue;
            }
            if (!$onlyFuzzy && $property['type'] == 'multi_field') {
                foreach (array_keys($property['fields']) as $field) {
                    if (strpos($field, 'edge_ngram') !== 0) {
                        $fields[] = $key . '.' . $field;
                    }
                }
            } elseif (0 !== strpos($key, 'sort_by_')) {
                $fields[] = $key;
            }
        }

        if ($this->_getHelper()->shouldSearchOnOptions()) {
            // Search on options labels too
            $fields[] = '_options';
        }

        return $fields;
    }

    /**
     * Checks if attribute is indexable.
     *
     * @param Mage_Catalog_Model_Resource_Eav_Attribute $attribute Attribute
     *
     * @return bool
     */
    protected function _isAttributeIndexable($attribute)
    {
        return $this->_getHelper()->isAttributeIndexable($attribute);
    }

    /**
     * Returns search helper.
     *
     * @return Smile_ElasticSearch_Helper_Elasticsearch
     */
    protected function _getHelper()
    {
        return Mage::helper('smile_elasticsearch/elasticsearch');
    }

    /**
     * Creates or updates Elasticsearch index.
     *
     * @link http://www.elasticsearch.org/guide/reference/mapping/core-types.html
     * @link http://www.elasticsearch.org/guide/reference/mapping/multi-field-type.html
     *
     * @return Smile_ElasticSearch_Model_Resource_Engine_Elasticsearch_Adapter
     *
     * @throws Exception
     */
    protected function _prepareIndex()
    {
        try {
            $indexSettings = $this->_getIndexSettings();
            $indices = $this->_client->indices();
            $params = array('index' => $this->_currentIndexName);

            if ($indices->exists($params)) {

                $indices->close($params);

                $settingsParams = $params;
                $settingsParams['body']['settings'] = $this->_getIndexSettings();
                $indices->putSettings($settingsParams);

                $mapping = $params;
                $mapping['body']['mappings']['product']['properties'] = $this->getIndexProperties();
                $indices->putMapping($mapping);

                $indices->open();
            } else {
                $params['body']['settings'] = $this->_getIndexSettings();
                $params['body']['settings']['number_of_shards'] = (int) $this->getConfig('number_of_shards');
                $params['body']['mappings']['product']['properties'] = $this->getIndexProperties();

                $properties = new Varien_Object($params);
                Mage::dispatchEvent('smile_elasticsearch_index_create_before', array('index_properties' => $properties ));
                $indices->create($properties->getData());
            }
        } catch (Exception $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError($e->getMessage());
            throw $e;
        }

        return $this;
    }

    /**
     * Handles search and facets.
     *
     * @param string $q      Fulltext query
     * @param array  $params Query params (filters, facets, ...)
     * @param string $type   Type of document
     *
     * @return array
     *
     * @throws Exception
     */
    public function search($query)
    {
        $indices = $this->_client->indices();
        $results = array();

        if ($indices->exists(array('index' => $this->_currentIndexName))) {
            Varien_Profiler::start('ELASTICSEARCH');

            $query->setAdapter($this)
                  ->setIndex($this->_currentIndexName);

            $results = $this->_client->search($query->getSearchParams());

            Varien_Profiler::stop('ELASTICSEARCH');
        }

        return $results;
    }

    /**
     * Launch an autocomplete query for products
     *
     * @param string $suggestQuery Text to be autocompleted
     *
     * @return array
     */
    public function autocompleteProducts($suggestQuery)
    {
        $suggestFieldName = $this->_getHelper()->getSuggestFieldName();
        $params = array('index' => $this->_currentIndexName);
        $params['body']['suggestions'] = array(
            'text'       => $suggestQuery,
            'completion' => array(
                'field' => $suggestFieldName,
                'fuzzy' => array('fuzziness' => 1, 'unicode_aware' => true)
            )
        );

        $response = $this->_client->suggest($params);

        return $response;
    }
}