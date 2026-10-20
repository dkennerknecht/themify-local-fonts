<?php
namespace ThemifyLocalFonts;

defined('ABSPATH') || exit;

final class Plugin {
    private static $manifest;
    private static $directory;
    private static $base_url;
    private static $ready = false;

    public static function is_ready(): bool {
        return self::$ready;
    }

    public static function init(): void {
        static $initialized = false;
        if ($initialized) { return; }
        $initialized = true;
        if (class_exists('Biohof_Local_Fonts', false)) {
            add_action('admin_notices', static function () {
                echo '<div class="notice notice-error"><p>Themify Local Fonts: Bitte zuerst die bisherige lokale MU-Font-Lösung deaktivieren.</p></div>';
            });
            return;
        }
        if (!defined('THEMIFY_DIR') || !function_exists('themify_get_google_font_lists')) {
            add_action('admin_notices', static function () {
                echo '<div class="notice notice-warning"><p>Themify Local Fonts benötigt ein aktives Themify-Theme oder das Themify-Builder-Plugin.</p></div>';
            });
            return;
        }
        self::$directory = FontManager::directory();
        $storage = FontManager::initialize_storage();
        if (is_wp_error($storage)) {
            add_action('admin_notices', static function () use ($storage) {
                echo '<div class="notice notice-error"><p>' . esc_html($storage->get_error_message()) . '</p></div>';
            });
            return;
        }
        self::$manifest = FontManager::manifest();
        self::$base_url = FontManager::base_url();
        FontManager::init();
        self::$ready = true;
        add_filter('style_loader_src', array(__CLASS__, 'stylesheet_url'), PHP_INT_MAX);
        add_filter('wp_resource_hints', array(__CLASS__, 'resource_hints'), PHP_INT_MAX, 2);
        // Outer buffer: Themify adds its font styles when its own buffer closes.
        // Starting on init also covers wp-login, Customizer and Builder responses.
        add_action('init', array(__CLASS__, 'start_buffer'), -9999);
        add_action('wp_head', array(__CLASS__, 'browser_adapter'), -9999);
        add_action('admin_head', array(__CLASS__, 'browser_adapter'), -9999);
        add_action('login_head', array(__CLASS__, 'browser_adapter'), -9999);
        add_action('customize_controls_print_scripts', array(__CLASS__, 'browser_adapter'), -9999);
    }

    private static function is_google_css(string $url): bool {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return $host === 'fonts.googleapis.com' && in_array($path, array('/css', '/css2'), true);
    }

    public static function stylesheet_url($url) {
        if (!is_string($url) || !self::is_google_css($url)) {
            return $url;
        }
        self::check_families($url);
        return FontManager::css_url();
    }

    private static function check_families(string $url): void {
        FontManager::ensure(FontManager::names_from_url($url));
        self::$manifest = FontManager::manifest();
    }

    public static function resource_hints(array $urls, string $relation): array {
        return array_values(array_filter($urls, static function ($item) {
            $url = is_array($item) ? ($item['href'] ?? '') : $item;
            return !preg_match('~^(?:https?:)?//fonts\.(?:googleapis|gstatic)\.com(?:[/:]|$)~i', (string) $url);
        }));
    }

    private static function font_urls(): array {
        self::$manifest = FontManager::manifest();
        $urls = array();
        foreach (self::$manifest['urls'] as $remote => $file) {
            $urls[$remote] = self::$base_url . $file;
            $urls[preg_replace('~^https?:~', '', $remote)] = self::$base_url . $file;
            $urls[preg_replace('~^https:~', 'http:', $remote)] = self::$base_url . $file;
        }
        return $urls;
    }

    public static function start_buffer(): void {
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        ob_start(array(__CLASS__, 'filter_output'));
    }

    public static function filter_output(string $output): string {
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false && stripos($header, 'application/xhtml+xml') === false) {
                return $output;
            }
        }
        if (stripos($output, 'fonts.gstatic.com') === false && stripos($output, 'fonts.googleapis.com') === false) {
            return $output;
        }
        // This is intentionally restricted to HTML responses. Do not modify JSON,
        // downloads, or scripts which merely contain an example Google URL.
        if (!preg_match('/<(?:html|head|body|style|link)\b/i', $output)) {
            return $output;
        }
        $output = preg_replace_callback('~<style\b[^>]*>(.*?)</style\s*>~is', static function ($match) {
            if (stripos($match[1], 'fonts.gstatic.com') === false) {
                return $match[0];
            }
            preg_match_all('~@font-face\s*\{[^}]*\}~i', $match[1], $font_faces);
            $names = array();
            foreach ($font_faces[0] as $face) {
                if (preg_match('/font-family\s*:\s*[\'"]?([^;\'",}]+)/i', $face, $family)) {
                    $names[] = trim($family[1]);
                }
            }
            $names = array_values(array_unique($names));
            if (FontManager::can_prepare()) {
                FontManager::ensure($names);
            }
            $css = strtr($match[1], self::font_urls());
            if (stripos($css, 'fonts.gstatic.com') !== false) {
                // Replace only affected font faces. Keep unrelated CSS and use
                // all prepared variants of the family if Google changes its URLs.
                $bundle = FontManager::absolute_css(file_get_contents(self::$directory . self::$manifest['stylesheet']));
                $replaced = array();
                $css = preg_replace_callback('~@font-face\s*\{[^}]*\}~i', static function ($face) use ($bundle, &$replaced) {
                    if (stripos($face[0], 'fonts.gstatic.com') === false) {
                        return $face[0];
                    }
                    preg_match('/font-family\s*:\s*[\'"]?([^;\'",}]+)/i', $face[0], $family);
                    $name = isset($family[1]) ? trim($family[1]) : '';
                    if (isset($replaced[$name])) {
                        return '';
                    }
                    $replaced[$name] = true;
                    preg_match_all('~@font-face\s*\{[^}]*\}~i', $bundle, $local_faces);
                    return implode("\n", array_filter($local_faces[0], static function ($local) use ($name) {
                        preg_match('/font-family\s*:\s*[\'"]?([^;\'",}]+)/i', $local, $family);
                        return isset($family[1]) && trim($family[1]) === $name;
                    }));
                }, $css);
            }
            return str_replace($match[1], $css, $match[0]);
        }, $output);
        $output = preg_replace_callback('~<link\b[^>]*>~i', static function ($match) {
            if (!preg_match('~\bhref\s*=\s*([\'"])(.*?)\1~is', $match[0], $href)) {
                return $match[0];
            }
            $url = html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (self::is_google_css($url)) {
                if (preg_match('~\brel\s*=\s*[\'"](?:preconnect|dns-prefetch)[\'"]~i', $match[0])) {
                    return '';
                }
                $tag = str_replace($href[0], 'href="' . esc_url(self::stylesheet_url($url)) . '"', $match[0]);
                return preg_replace('~\s+integrity\s*=\s*([\'"]).*?\1~is', '', $tag);
            }
            if (preg_match('~^(?:https?:)?//fonts\.(?:gstatic|googleapis)\.com(?:[/:]|$)~i', $url)) {
                $map = self::font_urls();
                return isset($map[$url]) ? str_replace($href[2], esc_url($map[$url]), $match[0]) : '';
            }
            return $match[0];
        }, $output);
        return $output;
    }

    public static function browser_adapter(): void {
        static $printed = false;
        if ($printed) {
            return;
        }
        $printed = true;
        self::$manifest = FontManager::manifest();
        $config = array(
            'css' => FontManager::css_url(),
            'families' => self::$manifest['families'],
            'endpoint' => admin_url('admin-ajax.php?action=tlf_local_fonts_css'),
            'nonce' => FontManager::can_prepare() ? wp_create_nonce('tlf_local_fonts') : '',
        );
        // Inline and early: the adapter must run before Builder/Customizer scripts.
        echo '<script id="tlf-local-fonts-adapter">';
        echo 'window.themifyLocalFonts=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';';
        readfile(dirname(__DIR__) . '/assets/adapter.js');
        echo '</script>';
    }
}
