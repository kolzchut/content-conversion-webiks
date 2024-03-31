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
function reformatLinks( string $html ): string {
   return preg_replace_callback('/<a\s+.*?href="(https?:\/\/[^"]+)"[^>]*>([^<]*)<\/a>/i', 'reformatLinksCallback', $html );
}

function reformatLinksCallback( $matches ): string {
    return $matches[2] . '(' . urldecode( $matches[1] ) . ')';
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
    'prop' => 'info|categories|pageprops', // Include categories and page info
    'inprop' => 'url',
    'clshow' => '!hidden',
    'cllimit' => 'max',
    'format' => 'json',
    'formatversion' => 2
];


// Initialize the CSV file
$csvFile = fopen( 'wiki_pages.csv', 'w' );
fputcsv( $csvFile, ['כותרת הדף', 'URL', 'סוג ערך', 'תחום תוכן ראשי', 'תקציר', 'תוכן', 'קטגוריות'] );

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
    if ( isset($data['continue'] ) ) {
        $params += $data['continue'];
    }

    echo ( 'Got ' . count( $data['query']['pages'] ) . ' pages' . ( isset( $data['continue'] ) ? ', not last batch' : '' ) . ":\n" );

    foreach ( $data['query']['pages'] as $page ) {
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
        $mainContent = convertHtmlToText( html_entity_decode($mainContent, ENT_COMPAT | ENT_HTML401, 'UTF-8') );


        $categories = [];
        if (isset($page['categories'])) {
            foreach ($page['categories'] as $category) {
                $categories[] = str_replace( [ 'קטגוריה:', '_'], [ '', ' ' ], $category['title'] );
            }
        }

        $articleType = getReadableArticleTypeFromCode( $page['pageprops']['ArticleType'] );
        $articleContentArea = $page['pageprops']['ArticleContentArea'] ?? 'unknown';

        fputcsv( $csvFile, [
            $page['title'],
            $page['fullurl'],
            $articleType,
            $articleContentArea,
            trim( $summary ),
            trim( $mainContent ),
            implode(PHP_EOL, $categories)
        ] );

        $counter += 1;
        if ( $counter % REPORT_EVERY === 0 ) {
            echo "\t$counter pages done\n";
        }
    }
} while ( isset( $data['continue'] ) );

fclose($csvFile);