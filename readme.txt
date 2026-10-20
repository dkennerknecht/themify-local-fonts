=== Themify Local Fonts ===
Contributors: dkennerknecht
Tags: themify, google fonts, local fonts, variable fonts
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Download Google Fonts selected in Themify and serve font files and stylesheets locally.

== Description ==

Automatically prepares fonts selected in Themify themes, Builder and Customizer,
including variable fonts and editor previews. Uses the current site's uploads
directory and keeps font weights, styles and Unicode subsets. No Themify core edits.

Requires an active Themify theme or standalone Themify Builder. Network activation
is not supported; activate per site. Independent community plugin.

== Installation ==

1. Upload themify-local-fonts.zip through Plugins > Add New > Upload Plugin.
2. Activate the plugin. Existing saved Themify fonts are prepared automatically.
3. Check Settings > Themify Local Fonts and sync existing fonts if needed.
4. Clear page/optimization/CDN caches and verify the website.

== Frequently Asked Questions ==

= Are new fonts downloaded automatically? =

Yes. Selecting a font in Themify triggers authenticated server-side preparation.
Save hooks also prepare fonts used in Builder, Customizer and style variables.
First use can take a few seconds. Visitors cannot initiate new downloads.

= Does it work without Themify? =

No. Without a supported Themify catalog, it displays an admin notice and leaves
website output unchanged. Custom uploads and system fonts are not modified.

= What happens on deactivation or deletion? =

Themify resumes its own font output. Downloaded fonts remain in uploads for cached
pages and later reuse. Clear caches. Remove the font directory manually if desired.

= Where is the full documentation? =

https://github.com/dkennerknecht/themify-local-fonts

== Changelog ==

= 1.0.0 =
* Initial portable WordPress plugin release.
* Automatic preparation of existing and newly selected Themify Google Fonts.
* Local variable fonts and Builder/Customizer preview stylesheets.
* Configurable WordPress upload paths, relative font URLs, admin sync and WP-CLI.
