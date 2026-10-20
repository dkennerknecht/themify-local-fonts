<?php
/**
 * Plugin Name: Themify Local Fonts
 * Plugin URI: https://github.com/dkennerknecht/themify-local-fonts
 * Description: Downloads Google Fonts selected in Themify and serves them locally, including Builder and Customizer previews.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: dkennerknecht
 * Author URI: https://github.com/dkennerknecht
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: themify-local-fonts
 * Update URI: https://github.com/dkennerknecht/themify-local-fonts
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/class-font-manager.php';
require_once __DIR__ . '/includes/class-plugin.php';

add_action('after_setup_theme', array('ThemifyLocalFonts\Plugin', 'init'), 20);

register_activation_hook(__FILE__, static function ($network_wide) {
    // Each site's uploads directory and catalog are initialized independently.
    // Network activation must not download assets into the wrong site's storage.
    if ($network_wide) {
        wp_die('Bitte Themify Local Fonts pro Website aktivieren. Eine Netzwerkaktivierung wird nicht unterstützt.');
    }
    \ThemifyLocalFonts\Plugin::init();
    if (\ThemifyLocalFonts\Plugin::is_ready()) {
        \ThemifyLocalFonts\FontManager::sync_existing();
    }
});
