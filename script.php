<?php

require_once __DIR__.'/vendor/autoload.php';
use Symfony\Component\CssSelector\CssSelectorConverter;

// Settings
const BATCH_SIZE = 100;
const REPORT_EVERY = BATCH_SIZE / 10;
const API_URL = 'https://www.kolzchut.org.il/w/he/api.php';

// If running against localhost, this might be set to true
const NO_SSL_CERT = false;

/**
 * Reformat an HTML snippet into plain text
 *
 * @param string $html The HTML string to search.
 * @return string the re-formatted text
 */
function convertHtmlToText(string $html ): string
{
    // Strip everything but links, which we will re-format
    $text = strip_tags( $html, '<a>' );
    $text = reformatEmailAndPhoneLinks( $text );
    $text = reformatLinks( $text );
    // Now strip the remaining tags, because who knows what's left
    $text = strip_tags( $text );
    return trim( $text );
}

/**
 * Reformat '<a href="mailto:{email}">{email}</a>' links to ({email}).
 * This is a special case, where the text of the link is the email address itself.
 *
 * @param string $html The HTML string to search.
 * @return string the re-formatted text
 */
function reformatEmailAndPhoneLinks(string $html ) : string {
    return preg_replace( '/<a\s+.*?href="(?:mailto|tel):([^"]+)"[^>]*>\1<\/a>/i', '(\1)', $html );
}

/**
 * Reformat <a href=""> links to Link_Text (URL) format
 *
 * @param string $html The HTML string to search.
 * @return string the re-formatted text
 */
function reformatLinks( string $html ): string {
   return preg_replace_callback('/<a\s+.*?href="([^"]+)"[^>]*>([^<]*)<\/a>/i', 'reformatLinksCallback', $html );
}

function reformatLinksCallback( $matches ): string {
    $url = str_replace( 'mailto:', '', $matches[1] );
    return $matches[2] . ' (' . urldecode( $url ) . ')';
}

/**
 * Make a DOMDocument from a fragment of HTML
 *
 * @param string $html The HTML fragment
 * @return DOMDocument $dom The DOMDocument instance.
 */
function getDomDocumentFromFragment( string $html ): DOMDocument {
    libxml_use_internal_errors( true );
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = true;
    // Unicode-compatibility - see:
    // https://stackoverflow.com/questions/8218230/php-domdocument-loadhtml-not-encoding-utf-8-correctly
    $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

    return $dom;
}

function removeEmptyElements( DOMDocument $dom ) {
    $xpath = new \DOMXPath( $dom );
    while (($node_list = $xpath->query('//*[not(*) and not(@*) and not(text()[normalize-space()])]')) && $node_list->length) {
        foreach ( $node_list as $node ) {
            $node->parentNode->removeChild( $node );
        }
    }
}

/**
 * Get the content (including child elements) of an element identified by a CSS selector.
 *
 * @param DOMDocument $dom The DOMDocument instance
 * @param string $selector The CSS selector to use to find the element.
 * @return string The content of the matched element, including child elements.
 */
function getElementContentBySelector( DOMDocument $dom, string $selector ): string {
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
function removeElementsBySelector( DOMDocument $dom, string $selector ): void {
    $converter = new CssSelectorConverter();
    $xpath = new DOMXpath( $dom );
    $elements = $xpath->query( $converter->toXPath( $selector ) );
    foreach ($elements as $element) {
        $element->parentNode->removeChild($element);
    }
}

/**
 * @param string|null $code
 *
 * @return string
 */
function getReadableArticleTypeFromCode( ?string $code ): string {
    $codesMap = [
        'mainpage' => 'עמוד ראשי',
        'portal' => 'פורטלים',
        'portal-subpage' => 'תתי-עמודים בפורטלים',
        'guide' => 'זכותונים ומדריכים',
        'right' => 'זכויות',
        'service' => 'שירותים',
        'term' => 'מושגים',
        'proceeding' => 'הליכים',
        'health' => 'מחלות, תסמונות ולקויות',
        'organization' => 'ארגוני סיוע',
        'government' => 'גורמי ממשל',
        'event' => 'אירועים',
        'ruling' => 'פסקי דין',
        'law' => 'חוקים ותקנות',
        'landingpage' => 'דפי נחיתה',
        'letter' => 'מכתבים וטפסים',
        'faq' => 'שאלות ותשובות',
        'newsletter' => 'ידיעון',
        'user' => 'משתמשים'
    ];

    return $codesMap[$code] ?? 'unknown';
}

function getParsedPage( int $pageId ) {
    $params = [
        'action' => 'parse',
        'pageid' => $pageId,
        'disablelimitreport' => 1,
        'disableeditsection' => 1,
        'disabletoc' => 1,
        'prop' => 'text|categories|properties',
        'format' => 'json',
        'formatversion' => 2
    ];

    $response = file_get_contents(API_URL . '?' . http_build_query($params) );

    if ( !$response ) {
        $error = error_get_last();
        echo "HTTP request failed. Error was: " . $error['message'];
        return false;
    }

    $data = json_decode( $response, true );

    return [
        'text' => $data['parse']['text'],
        'categories' => getOnlyVisibleCategories( $data['parse']['categories'] ?? [] ),
        'properties' => $data['parse']['properties'] ?? []
    ];
}

function getOnlyVisibleCategories( $categories ) {
    $visibleCategories = [];
    foreach ( $categories as $category => $props ) {
        if ( !isset( $props['hidden'] ) ) {
            $visibleCategories[] = str_replace( '_', ' ', $props['category'] );
        }
    }

    return $visibleCategories;
}

// Set the MediaWiki API endpoint
$baseUrl = 'https://local.dev.wikirights.org.il';
$apiUrl = $baseUrl . '/w/he/api.php';
$articleUrl = $baseUrl . '/he/';

if ( NO_SSL_CERT ) {
    // No SSL verification - this is dangerous when working on an external server!
    $arrContextOptions = [
        "ssl" => [
            "verify_peer" => false,
            "verify_peer_name" => false
        ]
    ];

    stream_context_set_default($arrContextOptions);
}

// Prepare the API request parameters
$params = [
    'action' => 'query',
    'generator' => 'allpages',
    'gaplimit' => BATCH_SIZE,
    'gapfilterredir' => 'nonredirects',
    'gapnamespace' => '0', // Main namespace
    'prop' => 'info',
    'inprop' => 'url',
    'format' => 'json',
    'formatversion' => 2
];

if ( START_FROM ) {
    $params['gapfrom'] = START_FROM;
}


// Initialize the CSV file
$csvFile = fopen( 'wiki_pages.' . date('Ymd\THis') . '.csv', 'w' );
fputcsv( $csvFile, [ 'id', 'כותרת הדף', 'URL', 'סוג ערך', 'תחום תוכן ראשי', 'תקציר', 'תוכן', 'תוכן HTML', 'קטגוריות'] );

// Make the API request and retrieve pages
$continueParam = null;
$counter = 0;
do {
    $response = file_get_contents(API_URL . '?' . http_build_query($params) );
    if ( !$response ) {
        $error = error_get_last();
        echo "HTTP request failed. Error was: " . $error['message'];
        break;
    }

    $data = json_decode($response, true);
    if ( isset($data['continue']['gapcontinue'] ) ) {
        $params['gapcontinue'] = $data['continue']['gapcontinue'];
    }

    echo ( 'Got ' . count( $data['query']['pages'] ) . ' pages' . ( isset( $data['continue']['gapcontinue'] ) ? ', not last batch' : '' ) . ":\n" );

    foreach ( $data['query']['pages'] as $page ) {

        // Ignore non-Hebrew pages
        if ( $page['pagelanguage'] !== 'he' ) {
            continue;
        };

        $pageData = getParsedPage( $page['pageid'] );
        $pageContent = $pageData['text'];
        $categories = $pageData['categories'];

        $dom = getDomDocumentFromFragment( $pageContent );

        // Extract the summary content
        $summary = getElementContentBySelector( $dom, '.article-summary' );
        $summary = convertHtmlToText( $summary );

        // Remove the summary from the document
        removeElementsBySelector( $dom, '.article-summary' );

        // Remove other useless elements
        removeElementsBySelector( $dom, '.toc-box' );

        // Remove maps - rare, probably only a single page, but still annoying
        removeElementsBySelector( $dom, '.maps-map' );

        removeEmptyElements( $dom );

        // Extract the main content
        $processedHtml = $dom->saveHTML();
        $processedHtml = html_entity_decode($processedHtml, ENT_COMPAT | ENT_HTML401, 'UTF-8');
        $mainContent = convertHtmlToText( $processedHtml );

        $articleType = $pageData['properties']['ArticleType'] ?? null;
        $articleType = getReadableArticleTypeFromCode( $articleType );
        $articleContentArea = $pageData['properties']['ArticleContentArea'] ?? 'unknown';

        fputcsv( $csvFile, [
            $page['pageid'],
            $page['title'],
            urldecode( $page['fullurl'] ),
            $articleType,
            $articleContentArea,
            trim( $summary ),
            trim( $mainContent ),
            trim( $processedHtml ),
            implode(PHP_EOL, $categories)
        ] );

        $counter += 1;
        if ( $counter % REPORT_EVERY === 0 ) {
            echo "\t$counter pages done\n";
        }
    }
} while ( isset( $data['continue'] ) );

fclose( $csvFile );
