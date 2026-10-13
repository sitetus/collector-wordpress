<?php
/**
 * Plugin Name:       Sitetus
 * Plugin URI:        https://sitetus.com
 * Description:       Lets the Sitetus monitoring panel read this site's health, inventory and update status through signed, read-only requests. Nothing leaves the server on its own.
 * Version:           0.5.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Codemarkt
 * Author URI:        https://www.codemarkt.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sitetus
 * Domain Path:       /languages
 *
 * The Sitetus panel the site owner paired the plugin with calls the site's
 * REST endpoint with signed requests; the plugin verifies each signature
 * with the public key stored during pairing and answers with a read-only
 * JSON envelope. Unsigned or invalid requests are answered with a bodiless
 * 404. The plugin contacts one external service, and only while the
 * optional core file integrity module is selected: while answering a
 * signed request it asks WordPress.org (api.wordpress.org), through
 * WordPress' own get_core_checksums(), for the official checksum list of
 * the installed WordPress version and language package.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SITETUS_PLUGIN_VERSION', '0.5.1');
define('SITETUS_PLUGIN_FILE', __FILE__);
define('SITETUS_PLUGIN_DIR', __DIR__);

require_once __DIR__.'/includes/autoload.php';

\Sitetus\WP\Plugin::boot();
