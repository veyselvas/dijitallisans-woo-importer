<?php
/**
 * Plugin Name:       Dijital Lisans WooCommerce Ürün Aktarıcı & AI SEO
 * Plugin URI:        https://veyselvas.com.tr
 * Description:       dijitallisans.com.tr sitesindeki dijital lisans ürünlerini, fiyat kuralları, hiyerarşik kategori eşlemesi, OpenRouter Yapay Zeka ile özgünleştirilmiş içerik ve Yoast SEO optimizasyonu ile WooCommerce mağazanıza aktarır. (Görsel indirilmez).
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Veysel Vas
 * Author URI:        https://github.com/veyselvas
 * Text Domain:       dijitallisans-importer
 * Domain Path:       /languages
 *
 * @package           Dijitallisans_Woo_Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DLI_VERSION', '1.0.0');
define('DLI_PLUGIN_FILE', __FILE__);
define('DLI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DLI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DLI_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Sınıfları dahil et
require_once DLI_PLUGIN_DIR . 'includes/class-dli-scraper.php';
require_once DLI_PLUGIN_DIR . 'includes/class-dli-ai-rewriter.php';
require_once DLI_PLUGIN_DIR . 'includes/class-dli-importer.php';
require_once DLI_PLUGIN_DIR . 'includes/class-dli-admin.php';

/**
 * Eklentiyi başlat
 */
function dli_init_plugin() {
    if (is_admin()) {
        DLI_Admin::init();
    }
}
add_action('plugins_loaded', 'dli_init_plugin');

/**
 * Eklentiler sayfasına "Ayarlar" ve "Ürünleri Aktar" kısayolu ekle
 */
function dli_action_links($links) {
    $action_links = array(
        '<a href="' . esc_url(admin_url('admin.php?page=dijitallisans-importer')) . '">' . __('Ürün Aktarıcı', 'dijitallisans-importer') . '</a>',
        '<a href="' . esc_url(admin_url('admin.php?page=dijitallisans-importer&tab=settings')) . '">' . __('Ayarlar', 'dijitallisans-importer') . '</a>',
    );
    return array_merge($action_links, $links);
}
add_filter('plugin_action_links_' . DLI_PLUGIN_BASENAME, 'dli_action_links');
