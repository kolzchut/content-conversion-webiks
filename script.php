<?php

require_once __DIR__.'/vendor/autoload.php';
use Symfony\Component\CssSelector\CssSelectorConverter;

// Set the MediaWiki API endpoint
$baseUrl = 'https://local.dev.wikirights.org.il';
$apiUrl = $baseUrl . '/w/he/api.php';
$articleUrl = $baseUrl . '/he/';

// No SSL verification - watch it!
$arrContextOptions=[
    "ssl"=> [
        "verify_peer"=>false,
        "verify_peer_name"=>false
    ]
];

stream_context_set_default( $arrContextOptions );

// Prepare the API request parameters
$params = [
    'action' => 'query',
    'generator' => 'allpages',
    'gaplimit' => 'max',
    'gapfilterredir' => 'nonredirects',
    'gapnamespace' => '0', // Main namespace
    'prop' => 'info|categories|pageprops', // Include categories and page info
    'inprop' => 'url',
    'clshow' => '!hidden',
    'cllimit' => 'max',
    'format' => 'json',
    'formatversion' => 2,
    'rawcontinue' => 1
];

// Initialize the CSV file
$csvFile = fopen('wiki_pages.csv', 'w');
fputcsv($csvFile, ['כותרת הדף', 'URL', 'סוג ערך', 'תחום תוכן ראשי', 'תקציר', 'תוכן', 'קטגוריות']);

// Make the API request and retrieve pages
$continueParam = null;
do {
    if ($continueParam !== null) {
        $params['apfrom'] = $continueParam;
    }

    $response = file_get_contents($apiUrl . '?' . http_build_query($params) );
    $data = json_decode($response, true);

    if (isset($data['continue'])) {
        $continueParam = $data['continue']['gapcontinue'];
    } else {
        $continueParam = null;
    }

    echo ( 'Got ' . count( $data['query']['pages'] ) . ' pages' . "\n\n" );

    foreach ($data['query']['pages'] as $page) {
        $pageContent = file_get_contents( $page['fullurl'] . '?action=render');
        $dom = getDomDocumentFromFragment( $pageContent );

        // Extract the summary content
        $summary = getElementContentBySelector( $dom, '.article-summary' );
        $summary = convertHtmlToText( $summary );

        // Remove the summary from the document
        removeElementsBySelector( $dom, '.article-summary' );

        // Remove other useless elements
        removeElementsBySelector( $dom, '.toc-box' );


        // Extract the main content
        $mainContent = $dom->saveHTML();
        $mainContent = convertHtmlToText( html_entity_decode($mainContent, ENT_COMPAT | ENT_HTML401, 'UTF-8') );;


        $categories = [];
        if (isset($page['categories'])) {
            foreach ($page['categories'] as $category) {
                $categories[] = $category['title'];
            }
        }

        $articleType = $page['pageprops']['ArticleType'] ?? 'unknown';
        $articleContentArea = $page['pageprops']['ArticleContentArea'] ?? 'unknown';

        fputcsv($csvFile, [$page['title'], $page['fullurl'], $articleType, $articleContentArea, trim( $summary ), trim( $mainContent ), implode(',', $categories)]);
    }
} while ($continueParam !== null);

fclose($csvFile);


/**
 * Reformat an HTML snippet into plain text
 *
 * @param string $html The HTML string to search.
 * @return string the re-formatted text
 */
function convertHtmlToText( $html ) {
    // Strip everything but links
    $text = strip_tags( $html, '<a>' );
    $text = reformatLinks( $text );
    return trim( $text );
}

/**
 * Reformat <a href=""> links to Link_Text (URL) format
 *
 * @param string $html The HTML string to search.
 * @return string the re-formatted text
 */
function reformatLinks( $html ) {
   return preg_replace_callback('/<a\s+.*?href="(https?:\/\/[^"]+)"[^>]*>([^<]*)<\/a>/i', 'reformatLinksCallback', $html );
}

function reformatLinksCallback( $matches ) {
    return $matches[2] . '(' . urldecode( $matches[1] ) . ')';
}

/**
 * Make a DOMDocument from a fragment of HTML
 *
 * @param string $html The HTML fragment
 * @return DOMDocument $dom The DOMDocument instance.
 */
function getDomDocumentFromFragment( $html ) {
    libxml_use_internal_errors( true );
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    // Unicode-compatibility - see:
    // https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
    $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );

    return $dom;
}

/**
 * Get the content (including child elements) of an element identified by a CSS selector.
 *
 * @param DOMDocument $dom The DOMDocument instance
 * @param string $selector The CSS selector to use to find the element.
 * @return string The content of the matched element, including child elements.
 */
function getElementContentBySelector( $dom, $selector ) {
    $converter = new CssSelectorConverter();
    $xpath = new DOMXpath( $dom );
    $elements = $xpath->query( $converter->toXPath( $selector ) );
    if ($elements->length > 0) {
        $element = $elements->item(0);
        $content = $dom->saveHTML($element);
        return $content;
    }

    return '';
}

/**
 * Remove elements from a DOMDocument based on a CSS selector.
 *
 * @param DOMDocument $dom The DOMDocument instance.
 * @param string $selector The CSS selector to use to find the elements to remove.
 */
function removeElementsBySelector(DOMDocument $dom, $selector )
{
    $converter = new CssSelectorConverter();
    $xpath = new DOMXpath( $dom );
    $elements = $xpath->query( $converter->toXPath( $selector ) );
    foreach ($elements as $element) {
        $element->parentNode->removeChild($element);
    }
}
