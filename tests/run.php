<?php
/** Dependency-free regression tests. Run: php tests/run.php */
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message) { throw new RuntimeException($message); });
$temporary = sys_get_temp_dir() . '/tlf-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700);
define('ABSPATH', $temporary . '/');
define('THEMIFY_DIR', $temporary . '/framework');
define('MB_IN_BYTES', 1048576);
define('HOUR_IN_SECONDS', 3600);
mkdir(THEMIFY_DIR . '/google-fonts', 0700, true);
file_put_contents(THEMIFY_DIR . '/google-fonts/google-fonts.php', '<?php return array("Test Font" => array("ss", array("r", "700i")), "Single Cut" => "ss", "Failed Font" => "ss", "Wrong Host" => "ss");');
class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
$GLOBALS['tlf_can_prepare'] = false;
$GLOBALS['tlf_baseurl'] = 'https://example.test/subdir/custom-media';
$GLOBALS['tlf_http_mode'] = 'ok';
$GLOBALS['tlf_requests'] = array();
function current_user_can($capability) { return $GLOBALS['tlf_can_prepare']; }
function wp_upload_dir(...$args) { global $temporary; return array('basedir' => $temporary . '/media', 'baseurl' => $GLOBALS['tlf_baseurl'], 'error' => false); }
function trailingslashit($path) { return rtrim($path, '/') . '/'; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0755, true); }
function wp_is_writable($path) { return is_writable($path); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function get_stylesheet() { return 'arbitrary-child-name'; }
function get_template() { return 'themify-test'; }
function get_current_user_id() { return 0; }
function add_action(...$args) {}
function add_filter(...$args) {}
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES); }
function wp_safe_remote_get($url, $args) {
    $GLOBALS['tlf_requests'][] = $url;
    if (strpos($url, 'fonts.googleapis.com') !== false) {
        if ($GLOBALS['tlf_http_mode'] === 'offline') { return new WP_Error('offline', 'Simulated offline response'); }
        $family = \ThemifyLocalFonts\FontManager::names_from_url($url)[0];
        $host = $GLOBALS['tlf_http_mode'] === 'wrong-host' ? 'example.org' : 'fonts.gstatic.com';
        $body = '@font-face{font-family:"' . $family . '";font-style:normal;font-weight:400;src:url(https://' . $host . '/s/test/' . rawurlencode($family) . '.woff2) format("woff2");unicode-range:U+0000-00FF;}';
    } else {
        $body = 'wOF2' . str_repeat('x', 128);
    }
    return array('response' => array('code' => 200), 'body' => $body);
}
require dirname(__DIR__) . '/includes/class-font-manager.php';
require dirname(__DIR__) . '/includes/class-plugin.php';
use ThemifyLocalFonts\FontManager;
use ThemifyLocalFonts\Plugin;
$count = 0;
function check($condition, $label) { global $count; if (!$condition) { throw new RuntimeException($label); } ++$count; echo "PASS $label\n"; }
function remove_tree($path) { foreach (scandir($path) as $name) { if ($name === '.' || $name === '..') { continue; } $file = $path . '/' . $name; is_dir($file) ? remove_tree($file) : unlink($file); } rmdir($path); }
try {
    check(FontManager::initialize_storage() === true, 'Fresh custom upload directory initializes without seeded assets');
    check(FontManager::manifest()['families'] === array(), 'Fresh manifest starts empty');
    check(strpos(FontManager::css_url(), '/subdir/custom-media/themify-local-fonts/') !== false, 'Custom upload URLs are honored');
    check(is_wp_error(FontManager::ensure(array('Test Font'))) && !$GLOBALS['tlf_requests'], 'Visitors cannot trigger downloads');
    $GLOBALS['tlf_can_prepare'] = true;
    check(FontManager::ensure(array('Test Font')) === true, 'Editor can prepare a new family');
    $manifest = FontManager::manifest();
    $css = file_get_contents(FontManager::directory() . $manifest['stylesheet']);
    check(strpos($css, 'https://') === false && strpos($css, 'fonts.gstatic.com') === false, 'Stored CSS has portable relative font URLs');
    check(strpos(FontManager::absolute_css($css), FontManager::base_url()) !== false, 'Inline and AJAX CSS resolve font URLs against uploads');
    $requests = count($GLOBALS['tlf_requests']);
    check(FontManager::ensure(array('Test Font')) === true && count($GLOBALS['tlf_requests']) === $requests, 'Prepared families do not download again');
    $GLOBALS['tlf_baseurl'] = 'https://moved.example/media';
    check(strpos(FontManager::absolute_css($css), 'https://moved.example/media/') !== false, 'Domain migration needs no CSS rewrite');
    FontManager::option_saved('theme_mods_arbitrary-child-name', array(), array('font' => 'Single Cut'));
    check(in_array('Single Cut', FontManager::manifest()['families'], true), 'Save hook supports arbitrary child-theme names and single-cut catalog entries');
    $before = file_get_contents(FontManager::directory() . 'manifest.json');
    $GLOBALS['tlf_http_mode'] = 'offline';
    check(is_wp_error(FontManager::ensure(array('Failed Font'))), 'Download failure returns an error');
    check(file_get_contents(FontManager::directory() . 'manifest.json') === $before, 'Failed download cannot publish an incomplete family');
    $GLOBALS['tlf_http_mode'] = 'wrong-host';
    $result = FontManager::ensure(array('Wrong Host'));
    check(is_wp_error($result) && $result->get_error_code() === 'font_url', 'Unexpected download hosts are rejected');
    check(file_get_contents(FontManager::directory() . 'manifest.json') === $before, 'Rejected host leaves the manifest unchanged');
    $GLOBALS['tlf_can_prepare'] = false;
    $initialization_lock = fopen(FontManager::directory() . '.manifest.lock', 'c');
    flock($initialization_lock, LOCK_EX);
    check(FontManager::initialize_storage() === true, 'Readers do not wait for a download lock');
    flock($initialization_lock, LOCK_UN); fclose($initialization_lock);
    check(FontManager::names_from_url('https://fonts.googleapis.com/css2?family=Test+Font:wght@400&family=Single+Cut&display=swap') === array('Test Font', 'Single Cut'), 'CSS2 repeated family parameters parse correctly');
    // Set Plugin's runtime paths without bootstrapping a WordPress installation.
    $reflection = new ReflectionClass(Plugin::class);
    foreach (array('directory' => FontManager::directory(), 'base_url' => FontManager::base_url(), 'manifest' => FontManager::manifest()) as $property => $value) {
        $field = $reflection->getProperty($property); $field->setAccessible(true); $field->setValue(null, $value);
    }
    $external = '<style>.keep{color:red}@font-face{font-family:"Test Font";src:url(https://fonts.gstatic.com/s/future-version.woff2)}</style>';
    $localized = Plugin::filter_output($external);
    check(strpos($localized, 'fonts.gstatic.com') === false && strpos($localized, '.keep{color:red}') !== false, 'New upstream versions use local faces without deleting surrounding CSS');
    $tag = Plugin::filter_output('<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Test+Font" integrity="sha256-obsolete">');
    check(strpos($tag, 'fonts.googleapis.com') === false && strpos($tag, 'integrity=') === false, 'Direct Google stylesheets localize and drop obsolete integrity');
    $spoof = 'https://fonts.googleapis.com.attacker.example/css?family=Test+Font';
    check(Plugin::stylesheet_url($spoof) === $spoof, 'Google host matching is exact');
    $json = '{"example":"https://fonts.googleapis.com/css?family=Test+Font"}';
    check(Plugin::filter_output($json) === $json, 'Non-HTML output remains unchanged');
    $hints = Plugin::resource_hints(array('https://fonts.gstatic.com', array('href' => 'https://fonts.googleapis.com'), 'https://example.org'), 'preconnect');
    check($hints === array('https://example.org'), 'Only Google Fonts resource hints are removed');
    echo "$count checks passed.\n";
} finally {
    remove_tree($temporary);
}
