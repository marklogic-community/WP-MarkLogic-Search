<?php
/*
 * This file is part of marklogic/wp-search
 *
 * @package     MarkLogic\WpSearch
 * @license     http://opensource.org/licenses/GPL-2.0 GPL-2.0+
 */

use Psr\Log\LogLevel;
use MarkLogic\MLPHP\MLPHP;
use MarkLogic\WpSearch\BulkImports;
use MarkLogic\WpSearch\Cron;
use MarkLogic\WpSearch\SearchPage;
use MarkLogic\WpSearch\DriverRegistry;
use MarkLogic\WpSearch\MlphpDriver;
use MarkLogic\WpSearch\Options;
use MarkLogic\WpSearch\SimpleLogger;
use MarkLogic\WpSearch\SyncManager;
use MarkLogic\WpSearch\CustomMLWWWConstraint;

function ml_wpsearch_load()
{
    BulkImports::init();
    Cron::init();
    add_filter('ml_wpsearch_persistable_type', 'ml_wpsearch_exclude_media', 10, 2);

    if (is_admin()) {
        Options::init();
    } else {
        SearchPage::init();
    }

    $mlClient = ml_wpsearch_create_client();
    if ($mlClient) {
        DriverRegistry::getInstance()->add('marklogic', new MlphpDriver($mlClient));
    }

}

function ml_wpsearch_get_logger()
{
    static $logger = null;

    if (!$logger) {
        $logger = new SimpleLogger(defined('WP_DEBUG') && WP_DEBUG ? LogLevel::DEBUG : LogLevel::ERROR);
    }

    return $logger;
}

function ml_wpsearch_create_client()
{
    $options = Options::getOptions();

    foreach (array('username', 'password') as $req) {
        if (empty($options[$req])) {
            return null;
        }
    }

    $ml = new MLPHP(array_replace(array(
        'logger'    => ml_wpsearch_get_logger(),
    ), $options));

    return $ml->getClient();
}

function ml_wpsearch_url()
{
    if (Options::replaceSearch()) {
        return home_url();
    }

    $pageId = Options::getSearchPage();

    return $pageId ? get_permalink($pageId) : null;
}

function ml_wpsearch_exclude_media($canPersist, $type)
{
    return $canPersist && 'attachment' !== $type->name;
}

function _ml_wpsearch_is_type_persistable($type)
{
    return $type && apply_filters('ml_wpsearch_persistable_type', (bool) $type->public, $type);
}

/**
 * Gets results for search page.
 *
 * @param string $querytext the search to perform
 * @return array [$result, $nextLink, $prevLink];
 */
function ml_wpsearch_search($querytext)
{
    $querytext = trim($querytext);
    if (!$querytext) {
        return [null, null, null];
    }

    $options = Options::getOptions();
    $results = ml_wpsearch_search_query($querytext, array(
        'start' => isset($_REQUEST['start']) ? $_REQUEST['start'] : 1,
        'pageLength' => isset($_REQUEST['pageLength']) ? $_REQUEST['pageLength'] : 10,
        'options' => $options['rest_config_option'],
        'transform' => $options['rest_transform'],
    ));
    if (null === $results)
    {
        return [null, null, null];
    }

    if ($results->getTotal() < 1) {
        return [$results, null, null];
    }

    // Paging, we'll provide the URLs here, but it's up to the view
    // to display them.
    $searchPageUrl = ml_wpsearch_url();
    $nextLink = null;
    if ($results->getCurrentPage() < $results->getTotalPages()) {
        $nextLink = add_query_arg(array(
            's' => urlencode($querytext),
            'start' => $results->getNextStart(),
            'pageLength' => $results->getPageLength(),
        ), $searchPageUrl);
    }

    $prevLink = null;
    if ($results->getCurrentPage() > 1) {
        $prevLink = add_query_arg(array(
            's' => urlencode($querytext),
            'start' => $results->getPreviousStart(),
            'pageLength' => $results->getPageLength(),
        ), $searchPageUrl);
    }

    return [$results, $nextLink, $prevLink];
}

/**
 * Queries MarkLogic server for search results.
 *
 * @param string|array $query  The query as either a string or array for structured queries.
 * @param array $params Search query parameters.
 * @return SearchResults|null
 */
function ml_wpsearch_search_query($query, $params)
{
    $driver = DriverRegistry::getInstance()->get('marklogic');
    $new_query = $query;

    if (is_string($query))
    {
        $new_query = trim($query);
        if (!$new_query)
        {
            return null;
        }
        $searchExcludeArr = explode(PHP_EOL, $options['search_exclude']);

        foreach ($searchExcludeArr as $searchExclude) {

            $exitLoop = false;

            if (preg_match('/(\w+)\s(.+)\s({?{?.+}?}?)/', $searchExclude, $matches)) {

                $elem = $matches[1];
                $oper = $matches[2];
                $value = $matches[3];

                if (preg_match('/^{{(\w+)}}?/', $value, $specialMatches)) {

                    switch($specialMatches[1]) {
                        case 'today':
                            $value = '"' . date("Y-m-d\TH:i:s+00:00") . '"';
                            break;
                        default:
                            $exitLoop = true;
                            break;
                    }
                }

                if ($exitLoop)
                    break;

                switch(ord($oper)) {
                    case 61:
                        $oper = ":";
                        break;
                    case 62:
                        $oper = "GT";
                        break;
                    case 38:
                        $oper = "LT";
                        break;
                    default:
                        break;
                }

                $new_query .= " AND -{$elem} {$oper} {$value}";

            }
        }
    }
    elseif (is_array($query))
    {
        if (!$query)
        {
            return null;
        }
        $new_query = wp_json_encode($query);
    }

    // Provides way for changing search query and parameters.
    $new_params = apply_filters('ml_wpsearch_search_query_params', wp_parse_args($params, array(
        'start' => 1,
        'pageLength' => 10,
        'view' => 'all',
        'options' => '',
        'transform' => '',
    )));

    $new_query = apply_filters('ml_wpsearch_search_query_value', $new_query, $params);

    $results = $driver->search(stripslashes(sanitize_text_field($new_query)), $new_params, is_array($query)); // Assume structured query for arrays.

    return $results;
}

function _ml_wpsearch_build_facet_url_query($querytext, $constraintQuery)
{
    if (preg_match("/(.*)AND(.*)/i", $querytext, $matches)) {
        return sprintf('?s=%s', urlencode(trim($matches[1])));
    }

    return sprintf('?s=%s', urlencode(
        $querytext.' AND '.$constraintQuery
    ));
}
