<?php
namespace ThemifyLocalFonts;
/** Server-side preparation of fonts selected by an authenticated editor. */
defined('ABSPATH') || exit;

final class FontManager {
    private static $catalog;
    private static $busy = false;

    public static function init(): void {
        add_action('wp_ajax_tlf_local_fonts_css', array(__CLASS__, 'serve_css'));
        add_action('wp_ajax_nopriv_tlf_local_fonts_css', array(__CLASS__, 'serve_css'));
        add_action('updated_option', array(__CLASS__, 'option_saved'), 20, 3);
        add_action('added_option', array(__CLASS__, 'option_added'), 20, 2);
        add_action('themify_builder_save_data', array(__CLASS__, 'builder_saved'), 20);
        add_filter('themify_google_fonts', array(__CLASS__, 'font_requests'), PHP_INT_MAX);
        add_action('admin_notices', array(__CLASS__, 'notices'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_tlf_sync_fonts', array(__CLASS__, 'sync_action'));
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('themify-local-fonts sync', array(__CLASS__, 'cli_sync'));
        }
    }

    public static function directory(): string {
        $uploads = wp_upload_dir(null, false);
        return trailingslashit($uploads['basedir']) . 'themify-local-fonts/';
    }

    public static function base_url(): string {
        $uploads = wp_upload_dir(null, false);
        return trailingslashit($uploads['baseurl']) . 'themify-local-fonts/';
    }

    public static function initialize_storage() {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error']) || !wp_mkdir_p(self::directory()) || !wp_is_writable(self::directory())) {
            return new \WP_Error('font_storage', 'Themify Local Fonts: Das Upload-Verzeichnis ist nicht beschreibbar.');
        }
        // Readers must never wait for an editor's network download lock.
        if (is_file(self::directory() . 'manifest.json')) {
            return self::validate_storage();
        }
        $lock = fopen(self::directory() . '.manifest.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) { fclose($lock); }
            return new \WP_Error('font_storage', 'Themify Local Fonts: Der lokale Font-Speicher ist nicht verfügbar.');
        }
        try {
            if (is_file(self::directory() . 'manifest.json')) {
                return self::validate_storage();
            }
            $manifest = array('version' => 1, 'families' => array(), 'urls' => array(), 'sha256' => array(),
                'family_stylesheets' => array(), 'stylesheet' => 'fonts-empty.css', 'revision' => 'empty');
            if (!self::write_atomic(self::directory() . 'fonts-empty.css', "/* Local font storage initialized. */\n")
                || !self::write_atomic(self::directory() . 'manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT) . "\n")) {
                return new \WP_Error('font_storage', 'Themify Local Fonts: Der Font-Speicher konnte nicht eingerichtet werden.');
            }
            return true;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function validate_storage() {
        $manifest = self::manifest();
        if (!isset($manifest['families'], $manifest['urls'], $manifest['stylesheet'])
            || !is_array($manifest['families']) || !is_array($manifest['urls'])
            || !is_string($manifest['stylesheet']) || basename($manifest['stylesheet']) !== $manifest['stylesheet']
            || !is_readable(self::directory() . $manifest['stylesheet'])) {
            return new \WP_Error('font_manifest', 'Themify Local Fonts: Das Font-Manifest ist ungültig oder die CSS-Datei fehlt. Bitte den Font-Speicher aus dem Backup wiederherstellen.');
        }
        return true;
    }

    /** Relative font URLs in static CSS survive domain and uploads-path changes. */
    public static function absolute_css(string $css): string {
        return preg_replace_callback('~url\(\s*([\'"]?)([a-f0-9]{24}\.woff2)\1\s*\)~i', static function ($match) {
            return 'url(' . self::base_url() . $match[2] . ')';
        }, $css);
    }

    public static function sync_existing(): array {
        if (!self::can_prepare()) {
            return array('prepared' => array(), 'errors' => array('Keine Berechtigung zur Font-Vorbereitung.'));
        }
        $names = array();
        foreach (array('themify_builder_google_fonts', 'themify_custom_fonts', 'themify_data',
            'theme_mods_' . get_stylesheet(), 'theme_mods_' . get_template()) as $option) {
            $names = array_merge($names, self::names_from_data(get_option($option, array())));
        }
        // Include default theme families, not only fonts explicitly saved in options.
        foreach (array_unique(array(get_template_directory(), get_stylesheet_directory())) as $theme_directory) {
            $file = $theme_directory . '/style.css';
            if (is_readable($file)) {
                $names = array_merge($names, self::names_from_data(file_get_contents($file)));
            }
        }
        global $wpdb;
        $cursor = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id LIMIT 100",
                '_themify_builder_settings_json', $cursor
            ));
            foreach ($rows as $row) {
                $cursor = (int) $row->meta_id;
                $names = array_merge($names, self::names_from_data($row->meta_value));
            }
        } while (count($rows) === 100);
        $report = array('prepared' => array(), 'errors' => array());
        foreach (array_unique($names) as $name) {
            $result = self::ensure(array($name));
            if (is_wp_error($result)) {
                $report['errors'][] = $name . ': ' . $result->get_error_message();
            } else {
                $report['prepared'][] = $name;
            }
        }
        return $report;
    }

    public static function cli_sync(): void {
        $report = self::sync_existing();
        foreach ($report['errors'] as $error) {
            \WP_CLI::warning($error);
        }
        if ($report['errors']) {
            \WP_CLI::error('Einige Schriften konnten nicht lokal vorbereitet werden.');
        }
        \WP_CLI::success(count($report['prepared']) . ' Schriftfamilien lokal verfügbar.');
    }

    public static function admin_menu(): void {
        add_options_page('Themify Local Fonts', 'Themify Local Fonts', 'manage_options', 'themify-local-fonts', array(__CLASS__, 'settings_page'));
    }

    public static function settings_page(): void {
        if (!current_user_can('manage_options')) { return; }
        $manifest = self::manifest();
        echo '<div class="wrap"><h1>Themify Local Fonts</h1><p>Neue Google-Schriften werden beim Auswählen und Speichern in Themify automatisch lokal vorbereitet.</p>';
        echo '<p>Lokal verfügbar: ' . esc_html(implode(', ', $manifest['families']) ?: 'Noch keine Schriften') . '</p>';
        echo '<p>Hier kannst du bereits gespeicherte Themify-Schriften erneut vorbereiten. Anschließend den Seiten-/CDN-Cache leeren.</p>';
        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post"><input type="hidden" name="action" value="tlf_sync_fonts">';
        wp_nonce_field('tlf_sync_fonts');
        submit_button('Vorhandene Schriften lokal vorbereiten');
        echo '</form></div>';
    }

    public static function sync_action(): void {
        if (!current_user_can('manage_options')) { wp_die('Keine Berechtigung.', '', array('response' => 403)); }
        check_admin_referer('tlf_sync_fonts');
        self::sync_existing();
        wp_safe_redirect(admin_url('options-general.php?page=themify-local-fonts'));
        exit;
    }

    public static function manifest(): array {
        $path = self::directory() . 'manifest.json';
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($data) ? $data : array();
    }

    public static function can_prepare(): bool {
        return current_user_can('edit_theme_options') || current_user_can('edit_posts') || current_user_can('edit_pages');
    }

    public static function css_url(): string {
        $manifest = self::manifest();
        $version = $manifest['revision'] ?? filemtime(self::directory() . $manifest['stylesheet']);
        return self::base_url() . $manifest['stylesheet'] . '?v=' . rawurlencode((string) $version);
    }

    private static function catalog(): array {
        if (self::$catalog === null) {
            self::$catalog = array();
            if (defined('THEMIFY_DIR') && is_file(THEMIFY_DIR . '/google-fonts/google-fonts.php')) {
                $fonts = include THEMIFY_DIR . '/google-fonts/google-fonts.php';
                foreach ($fonts as $name => $details) {
                    // Families with only a regular cut are stored as a category
                    // string (e.g. "ss"), not a [category, variants] pair.
                    self::$catalog[$name] = is_array($details) && isset($details[1]) && is_array($details[1])
                        ? $details[1] : array('r');
                }
            }
            if (function_exists('themify_get_variable_fonts_list')) {
                self::$catalog = array_merge(self::$catalog, themify_get_variable_fonts_list());
            }
        }
        return self::$catalog;
    }

    public static function names_from_url(string $url): array {
        preg_match_all('/[?&]family=([^&]+)/', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches);
        $names = array();
        foreach ($matches[1] as $families) {
            foreach (explode('|', urldecode($families)) as $family) {
                $name = trim(explode(':', $family, 2)[0]);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        return array_values(array_unique($names));
    }

    private static function names_from_data($data, int $depth = 0): array {
        if ($depth > 20) {
            return array();
        }
        $names = array();
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($key) && isset(self::catalog()[$key])) {
                    $names[] = $key;
                }
                $names = array_merge($names, self::names_from_data($value, $depth + 1));
            }
        } elseif (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                return self::names_from_data($decoded, $depth + 1);
            }
            // Values saved by Themify include "Family:400,700|Other+Family".
            foreach (explode('|', $data) as $value) {
                $name = trim(explode(':', $value, 2)[0], " \t\n\r\0\x0B\"'");
                $name = trim(explode(',', $name, 2)[0], " \t\n\r\0\x0B\"'");
                $name = str_replace('+', ' ', $name);
                if (isset(self::catalog()[$name])) {
                    $names[] = $name;
                }
            }
            if (stripos($data, 'font-family') !== false) {
                preg_match_all('/font-family\s*:\s*[\'"]?([^;\'",}]+)/i', $data, $matches);
                foreach ($matches[1] as $name) {
                    if (isset(self::catalog()[trim($name)])) {
                        $names[] = trim($name);
                    }
                }
            }
        }
        return array_values(array_unique($names));
    }

    private static function prepare_data($data): void {
        if (self::$busy || !self::can_prepare()) {
            return;
        }
        self::ensure(self::names_from_data($data));
    }

    public static function option_saved(string $name, $old, $value): void {
        if (in_array($name, array('themify_builder_google_fonts', 'themify_custom_fonts', 'themify_data'), true)
            || in_array($name, array('theme_mods_' . get_stylesheet(), 'theme_mods_' . get_template()), true)) {
            self::prepare_data($value);
        }
    }

    public static function option_added(string $name, $value): void {
        self::option_saved($name, null, $value);
    }

    public static function builder_saved($post_id): void {
        self::prepare_data(get_post_meta((int) $post_id, '_themify_builder_settings_json', true));
    }

    public static function font_requests($fonts) {
        self::prepare_data($fonts);
        return $fonts;
    }

    public static function ensure(array $names) {
        $manifest = self::manifest();
        foreach (array_unique($names) as $name) {
            if (in_array($name, $manifest['families'] ?? array(), true)) {
                continue;
            }
            if (!self::can_prepare()) {
                return new \WP_Error('font_not_prepared', 'Die Schrift ist noch nicht lokal vorbereitet.');
            }
            $result = self::prepare($name);
            if (is_wp_error($result)) {
                self::remember_error($name . ': ' . $result->get_error_message());
                return $result;
            }
            $manifest = self::manifest();
        }
        return true;
    }

    private static function google_url(string $name): string {
        $variables = function_exists('themify_get_variable_fonts_list') ? themify_get_variable_fonts_list() : array();
        if (isset($variables[$name]) && function_exists('themify_get_variable_font_css2_param')) {
            return 'https://fonts.googleapis.com/css2?' . themify_get_variable_font_css2_param($name) . '&display=swap';
        }
        $variants = self::catalog()[$name];
        $pairs = array();
        $italic = false;
        foreach ($variants as $variant) {
            $variant = (string) $variant;
            $ital = substr($variant, -1) === 'i' ? 1 : 0;
            $weight = (int) rtrim($variant, 'i');
            if ($weight === 0) {
                $weight = 400;
            }
            $italic = $italic || $ital === 1;
            $pairs[$ital . ',' . $weight] = array($ital, $weight);
        }
        $pairs = array_values($pairs);
        usort($pairs, static function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
        $tuples = array_map(static function ($pair) use ($italic) {
            return $italic ? implode(',', $pair) : (string) $pair[1];
        }, $pairs);
        return 'https://fonts.googleapis.com/css2?family=' . str_replace('%20', '+', rawurlencode($name))
            . ':' . ($italic ? 'ital,wght@' : 'wght@') . implode(';', $tuples) . '&display=swap';
    }

    private static function fetch(string $url, int $limit) {
        $response = wp_safe_remote_get($url, array(
            'timeout' => 20, 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => $limit,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36',
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('font_http', 'Google konnte die Schrift nicht bereitstellen (HTTP ' . wp_remote_retrieve_response_code($response) . ').');
        }
        $body = wp_remote_retrieve_body($response);
        return strlen($body) >= $limit ? new \WP_Error('font_size', 'Die Font-Antwort ist zu groß.') : $body;
    }

    private static function write_atomic(string $file, string $data): bool {
        $temp = tempnam(self::directory(), '.font-');
        if ($temp === false) {
            return false;
        }
        if (file_put_contents($temp, $data) !== strlen($data)) {
            unlink($temp);
            return false;
        }
        chmod($temp, 0644);
        if (!rename($temp, $file)) {
            unlink($temp);
            return false;
        }
        return true;
    }

    public static function prepare(string $name) {
        if (!self::can_prepare()) {
            return new \WP_Error('font_permission', 'Keine Berechtigung zum Vorbereiten von Schriften.');
        }
        if (!isset(self::catalog()[$name])) {
            return new \WP_Error('font_unknown', 'Die Schrift ist nicht im Themify-Google-Font-Katalog enthalten.');
        }
        if (self::$busy) {
            return new \WP_Error('font_busy', 'Eine Schrift wird bereits vorbereitet.');
        }
        $lock = fopen(self::directory() . '.manifest.lock', 'c');
        if (!$lock) {
            return new \WP_Error('font_directory', 'Das lokale Font-Verzeichnis ist nicht beschreibbar.');
        }
        // Do not publish partially downloaded families; serialize concurrent previews/saves.
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return new \WP_Error('font_lock', 'Die Font-Verwaltung ist vorübergehend beschäftigt.');
        }
        self::$busy = true;
        try {
            $manifest = self::manifest();
            if (in_array($name, $manifest['families'], true)) {
                return true;
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(180);
            }
            $css = self::fetch(self::google_url($name), 2 * MB_IN_BYTES);
            if (is_wp_error($css)) {
                return $css;
            }
            if (strpos($css, '@font-face') === false || stripos($css, '@import') !== false) {
                return new \WP_Error('font_css', 'Google hat keine gültigen Font-Deklarationen geliefert.');
            }
            preg_match_all('~url\(\s*[\'"]?([^\s\)\'"]+)~i', $css, $matches);
            $urls = array_values(array_unique($matches[1]));
            if (!$urls || count($urls) > 256) {
                return new \WP_Error('font_css', 'Die Font-Antwort enthält keine unterstützte Dateiliste.');
            }
            $size = 0;
            foreach ($urls as $url) {
                $parts = wp_parse_url($url);
                if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'fonts.gstatic.com'
                    || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
                    return new \WP_Error('font_url', 'Die Font-Antwort enthält eine unerwartete Download-Adresse.');
                }
                $file = $manifest['urls'][$url] ?? (substr(hash('sha256', $url), 0, 24) . '.woff2');
                if (!is_file(self::directory() . $file)) {
                    $font = self::fetch($url, 10 * MB_IN_BYTES);
                    if (is_wp_error($font)) {
                        return $font;
                    }
                    $size += strlen($font);
                    if (substr($font, 0, 4) !== 'wOF2' || $size > 80 * MB_IN_BYTES) {
                        return new \WP_Error('font_file', 'Eine Font-Datei hat ein ungültiges Format oder ist zu groß.');
                    }
                    if (!self::write_atomic(self::directory() . $file, $font)) {
                        return new \WP_Error('font_write', 'Eine Font-Datei konnte nicht gespeichert werden.');
                    }
                }
                $manifest['urls'][$url] = $file;
                $manifest['sha256'][$file] = hash_file('sha256', self::directory() . $file);
                $css = str_replace($url, $file, $css);
            }
            if (stripos($css, 'fonts.gstatic.com') !== false || stripos($css, 'fonts.googleapis.com') !== false) {
                return new \WP_Error('font_css', 'Es konnten nicht alle Font-Adressen lokal ersetzt werden.');
            }
            $family_file = 'family-' . substr(hash('sha256', $name), 0, 20) . '.css';
            if (!self::write_atomic(self::directory() . $family_file, $css)) {
                return new \WP_Error('font_write', 'Das lokale Font-CSS konnte nicht gespeichert werden.');
            }
            $bundle = file_get_contents(self::directory() . $manifest['stylesheet']) . "\n" . $css . "\n";
            // Immutable bundle URL keeps old cached pages functional during publication.
            $manifest['revision'] = substr(hash('sha256', $bundle), 0, 16);
            $manifest['stylesheet'] = 'fonts-' . $manifest['revision'] . '.css';
            $manifest['family_stylesheets'][$name] = $family_file;
            $manifest['families'][] = $name;
            if (!self::write_atomic(self::directory() . $manifest['stylesheet'], $bundle)
                || !self::write_atomic(self::directory() . 'manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n")) {
                return new \WP_Error('font_manifest', 'Die lokale Schrift konnte nicht freigeschaltet werden.');
            }
            return true;
        } finally {
            self::$busy = false;
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function serve_css(): void {
        $raw = isset($_GET['families']) && is_string($_GET['families']) ? wp_unslash($_GET['families']) : '';
        $names = json_decode($raw, true);
        $valid = is_array($names) && count($names) > 0 && count($names) <= 12;
        if ($valid) {
            foreach ($names as $name) {
                if (!is_string($name) || strlen($name) > 150 || !isset(self::catalog()[$name])) {
                    $valid = false;
                    break;
                }
            }
        }
        nocache_headers();
        header('Content-Type: text/css; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if (!$valid) {
            status_header(400);
            echo '/* Ungültige Font-Anforderung. */';
            exit;
        }
        $known = self::manifest()['families'] ?? array();
        $missing = array_diff($names, $known);
        if ($missing) {
            $nonce = isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce']) ? wp_unslash($_GET['_wpnonce']) : '';
            if (!self::can_prepare() || !wp_verify_nonce($nonce, 'tlf_local_fonts')) {
                status_header(403);
                echo '/* Diese Schrift muss zuerst im WordPress-Backend vorbereitet werden. */';
                exit;
            }
            $result = self::ensure($names);
            if (is_wp_error($result)) {
                status_header(503);
                echo '/* Die Schrift konnte nicht lokal vorbereitet werden. Bitte erneut versuchen. */';
                exit;
            }
        }
        $manifest = self::manifest();
        echo self::absolute_css(file_get_contents(self::directory() . $manifest['stylesheet']));
        exit;
    }

    private static function remember_error(string $message): void {
        error_log('Themify Local Fonts: ' . $message);
        if (get_current_user_id()) {
            set_transient('tlf_font_error_' . get_current_user_id(), $message, HOUR_IN_SECONDS);
        }
    }

    public static function notices(): void {
        $key = 'tlf_font_error_' . get_current_user_id();
        $error = get_transient($key);
        if ($error && self::can_prepare()) {
            echo '<div class="notice notice-error"><p>' . esc_html('Lokale Google Fonts: ' . $error . ' Bitte die Schrift erneut auswählen oder die Seite erneut speichern.') . '</p></div>';
            delete_transient($key);
        }
    }
}
