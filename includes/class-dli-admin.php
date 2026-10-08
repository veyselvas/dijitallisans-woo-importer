<?php
/**
 * Dijital Lisans Admin Yönetim ve Arayüz Sınıfı
 *
 * @package Dijitallisans_Woo_Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class DLI_Admin {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));

        // AJAX Eylemleri
        add_action('wp_ajax_dli_fetch_sitemap', array(__CLASS__, 'ajax_fetch_sitemap'));
        add_action('wp_ajax_dli_import_single', array(__CLASS__, 'ajax_import_single'));
        add_action('wp_ajax_dli_test_openrouter', array(__CLASS__, 'ajax_test_openrouter'));
        add_action('wp_ajax_dli_save_settings', array(__CLASS__, 'ajax_save_settings'));
    }

    public static function register_menu() {
        add_menu_page(
            __('Dijital Lisans Aktarıcı', 'dijitallisans-importer'),
            __('Dijital Lisans', 'dijitallisans-importer'),
            'manage_options',
            'dijitallisans-importer',
            array(__CLASS__, 'render_admin_page'),
            'dashicons-cloud-download',
            56
        );
    }

    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_dijitallisans-importer') {
            return;
        }

        wp_enqueue_style(
            'dli-admin-css',
            DLI_PLUGIN_URL . 'assets/css/admin-style.css',
            array(),
            DLI_VERSION
        );

        wp_enqueue_script(
            'dli-admin-js',
            DLI_PLUGIN_URL . 'assets/js/admin-script.js',
            array('jquery'),
            DLI_VERSION,
            true
        );

        wp_localize_script('dli-admin-js', 'dli_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dli_ajax_nonce'),
        ));
    }

    /**
     * Admin Ana Sayfa Arayüzü
     */
    public static function render_admin_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'bulk';

        $api_key             = get_option('dli_openrouter_api_key', '');
        $model               = get_option('dli_openrouter_model', 'google/gemini-2.0-flash-001');
        $enable_ai           = get_option('dli_enable_ai', 'yes');
        $keep_original_title = get_option('dli_keep_original_title', 'yes');
        $margin_fixed        = get_option('dli_margin_fixed', '0');
        $margin_percent = get_option('dli_margin_percent', '0');
        $product_status = get_option('dli_product_status', 'publish');
        $duplicate_mode = get_option('dli_duplicate_mode', 'update');
        $is_virtual     = get_option('dli_is_virtual', 'yes');
        ?>
        <div class="wrap dli-container">
            <header class="dli-header">
                <div class="dli-header-brand">
                    <span class="dashicons dashicons-cloud-download dli-header-icon"></span>
                    <div>
                        <h1 class="dli-title">Dijital Lisans Aktarıcı & AI SEO</h1>
                        <p class="dli-subtitle">dijitallisans.com.tr &rarr; WooCommerce &bull; OpenRouter AI & Yoast SEO Destekli</p>
                    </div>
                </div>
                <div class="dli-header-badge">
                    <span>v<?php echo esc_html(DLI_VERSION); ?></span>
                </div>
            </header>

            <nav class="dli-nav-tabs">
                <a href="#tab-bulk" class="dli-nav-tab <?php echo $active_tab === 'bulk' ? 'active' : ''; ?>" data-tab="bulk">
                    <span class="dashicons dashicons-database-import"></span> Toplu Aktarım (Sitemap)
                </a>
                <a href="#tab-single" class="dli-nav-tab <?php echo $active_tab === 'single' ? 'active' : ''; ?>" data-tab="single">
                    <span class="dashicons dashicons-admin-links"></span> Hızlı Aktarım (Tek URL)
                </a>
                <a href="#tab-settings" class="dli-nav-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-tab="settings">
                    <span class="dashicons dashicons-admin-settings"></span> Ayarlar & OpenRouter AI
                </a>
            </nav>

            <div class="dli-tab-content-wrapper">
                <!-- TAB 1: TOPLU AKTARIM (SITEMAP) -->
                <div id="tab-bulk" class="dli-tab-content <?php echo $active_tab === 'bulk' ? 'active' : ''; ?>">
                    <div class="dli-card">
                        <div class="dli-card-header">
                            <h2 class="dli-card-title">Sitemap Ürün Listesi</h2>
                            <div class="dli-header-actions">
                                <button type="button" id="dli-btn-scan-sitemap" class="button button-primary">
                                    <span class="dashicons dashicons-search"></span> Sitemap'ten Ürünleri Getir
                                </button>
                            </div>
                        </div>

                        <div class="dli-card-body">
                            <p class="dli-desc">
                                Kaynak sitedeki (<strong>dijitallisans.com.tr</strong>) tüm ürünler sitemap taranarak listelenir. İstediğiniz ürünleri tek tek veya topluca seçip içe aktarabilirsiniz.
                            </p>

                            <!-- Filtreleme ve Kontrol Barı -->
                            <div class="dli-table-controls" id="dli-table-controls" style="display:none;">
                                <div class="dli-filter-left">
                                    <label>
                                        <input type="checkbox" id="dli-select-all"> <strong>Tümünü Seç</strong>
                                    </label>
                                    <span class="dli-selected-count">(<strong id="dli-count-selected">0</strong> ürün seçili)</span>
                                </div>
                                <div class="dli-filter-right">
                                    <input type="search" id="dli-search-box" placeholder="Listede ürün ara..." class="regular-text">
                                    <button type="button" id="dli-btn-start-import" class="button button-primary button-hero" disabled>
                                        <span class="dashicons dashicons-update"></span> Seçilenleri İçe Aktar
                                    </button>
                                </div>
                            </div>

                            <!-- İlerleme Bölümü -->
                            <div class="dli-progress-box" id="dli-progress-box" style="display:none;">
                                <div class="dli-progress-header">
                                    <span class="dli-progress-status-text" id="dli-progress-status-text">İçe aktarılıyor...</span>
                                    <span class="dli-progress-percentage" id="dli-progress-percentage">%0</span>
                                </div>
                                <div class="dli-progress-bar-bg">
                                    <div class="dli-progress-bar-fill" id="dli-progress-bar-fill" style="width: 0%;"></div>
                                </div>
                                <div class="dli-progress-stats">
                                    <span>Toplam: <strong id="dli-stat-total">0</strong></span>
                                    <span>Tamamlanan: <strong id="dli-stat-done" class="dli-text-success">0</strong></span>
                                    <span>Hata: <strong id="dli-stat-error" class="dli-text-danger">0</strong></span>
                                    <button type="button" id="dli-btn-stop-import" class="button button-secondary button-small" style="margin-left: auto;">
                                        İşlemi Durdur
                                    </button>
                                </div>
                            </div>

                            <!-- Canlı Log Konsolu -->
                            <div class="dli-console" id="dli-console" style="display:none;">
                                <div class="dli-console-header">
                                    <span>İşlem Günlüğü</span>
                                    <button type="button" id="dli-console-clear" class="button-link">Temizle</button>
                                </div>
                                <div class="dli-console-body" id="dli-console-logs"></div>
                            </div>

                            <!-- Ürün Tablosu -->
                            <div class="dli-table-responsive" id="dli-table-wrapper">
                                <div id="dli-sitemap-empty" class="dli-empty-state">
                                    <span class="dashicons dashicons-media-spreadsheet dli-empty-icon"></span>
                                    <h3>Henüz sitemap taranmadı</h3>
                                    <p>Yukarıdaki "Sitemap'ten Ürünleri Getir" butonuna tıklayarak ürünleri listeleyebilirsiniz.</p>
                                </div>
                                <table class="wp-list-table widefat fixed striped" id="dli-products-table" style="display:none;">
                                    <thead>
                                        <tr>
                                            <th scope="col" style="width: 40px;"><input type="checkbox" id="dli-th-select-all"></th>
                                            <th scope="col" style="width: 40%;">Ürün Adı</th>
                                            <th scope="col">Kaynak URL</th>
                                            <th scope="col" style="width: 140px;">Durum</th>
                                            <th scope="col" style="width: 100px; text-align: right;">İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dli-products-tbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: TEK ÜRÜN AKTARIMI -->
                <div id="tab-single" class="dli-tab-content <?php echo $active_tab === 'single' ? 'active' : ''; ?>">
                    <div class="dli-card">
                        <div class="dli-card-header">
                            <h2 class="dli-card-title">Hızlı Tek Ürün İçe Aktarma</h2>
                        </div>
                        <div class="dli-card-body">
                            <p class="dli-desc">
                                Dijitallisans sitesindeki herhangi bir ürünün doğrudan linkini yapıştırarak saniyeler içinde WooCommerce mağazanıza aktarabilirsiniz.
                            </p>
                            <form id="dli-form-single-import" class="dli-form-inline">
                                <div class="dli-input-group">
                                    <span class="dashicons dashicons-admin-links dli-input-icon"></span>
                                    <input type="url" id="dli-single-url" placeholder="https://www.dijitallisans.com.tr/ornek-urun-lisansi/" class="large-text" required>
                                </div>
                                <button type="submit" id="dli-btn-single-submit" class="button button-primary button-hero">
                                    <span class="dashicons dashicons-download"></span> Şimdi Aktar
                                </button>
                            </form>

                            <div id="dli-single-result" class="dli-result-box" style="display:none;"></div>
                        </div>
                    </div>
                </div>

                <!-- TAB 3: AYARLAR -->
                <div id="tab-settings" class="dli-tab-content <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
                    <form id="dli-form-settings">
                        <div class="dli-card">
                            <div class="dli-card-header">
                                <h2 class="dli-card-title">🤖 OpenRouter Yapay Zeka & Yoast SEO Ayarları</h2>
                            </div>
                            <div class="dli-card-body">
                                <p class="dli-desc">
                                    OpenRouter API anahtarınızı girerek ürün açıklamalarını, başlıklarını ve Yoast SEO meta alanlarını (SEO Başlığı, Meta Açıklaması ve Odak Anahtar Kelimesi) otomatik özgünleştirebilirsiniz.
                                </p>

                                <table class="form-table" role="presentation">
                                    <tr>
                                        <th scope="row"><label for="dli_enable_ai">Yapay Zeka Özgünleştirme</label></th>
                                        <td>
                                            <label class="dli-switch">
                                                <input type="checkbox" id="dli_enable_ai" name="dli_enable_ai" value="yes" <?php checked($enable_ai, 'yes'); ?>>
                                                <span class="dli-slider"></span>
                                            </label>
                                            <p class="description">Aktif olduğunda başlık, açıklama ve Yoast SEO verileri OpenRouter AI ile yeniden yazılır.</p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><label for="dli_openrouter_api_key">OpenRouter API Key</label></th>
                                        <td>
                                            <div style="display: flex; gap: 8px; align-items: center; max-width: 600px;">
                                                <input type="text" id="dli_openrouter_api_key" name="dli_openrouter_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="sk-or-v1-..." autocomplete="off" spellcheck="false" style="font-family: monospace;">
                                                <button type="button" id="dli-btn-test-ai" class="button button-secondary">
                                                    Bağlantıyı Test Et
                                                </button>
                                            </div>
                                            <span id="dli-ai-test-result" style="display: inline-block; margin-top: 5px;"></span>
                                            <p class="description">OpenRouter API anahtarınızı <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a> adresinden temin edebilirsiniz (Anahtar <code>sk-or-v1-...</code> şeklinde başlar).</p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><label for="dli_openrouter_model">Kullanılacak AI Modeli</label></th>
                                        <td>
                                            <select id="dli_openrouter_model_select" style="max-width: 350px;">
                                                <option value="google/gemini-2.0-flash-001" <?php selected($model, 'google/gemini-2.0-flash-001'); ?>>Google Gemini 2.0 Flash (Hızlı & Tavsiye Edilen)</option>
                                                <option value="openai/gpt-4o-mini" <?php selected($model, 'openai/gpt-4o-mini'); ?>>OpenAI GPT-4o Mini (Ekonomik & Güçlü)</option>
                                                <option value="anthropic/claude-3.5-sonnet" <?php selected($model, 'anthropic/claude-3.5-sonnet'); ?>>Claude 3.5 Sonnet (Üst Seviye Kalite)</option>
                                                <option value="meta-llama/llama-3.3-70b-instruct" <?php selected($model, 'meta-llama/llama-3.3-70b-instruct'); ?>>Meta Llama 3.3 70B</option>
                                                <option value="custom">Özel Model Adı Gir...</option>
                                            </select>
                                            <div id="dli_custom_model_wrapper" style="margin-top: 8px; <?php echo in_array($model, array('google/gemini-2.0-flash-001', 'openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet', 'meta-llama/llama-3.3-70b-instruct')) ? 'display:none;' : ''; ?>">
                                                <input type="text" id="dli_openrouter_model" name="dli_openrouter_model" value="<?php echo esc_attr($model); ?>" class="regular-text" placeholder="Örn: deepseek/deepseek-chat">
                                            </div>
                                            <p class="description">Dilediğiniz OpenRouter model kimliğini girebilirsiniz.</p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><label for="dli_keep_original_title">Ürün Başlıkları</label></th>
                                        <td>
                                            <select id="dli_keep_original_title" name="dli_keep_original_title" style="max-width: 400px;">
                                                <option value="yes" <?php selected($keep_original_title, 'yes'); ?>>Orijinal Başlığı Birebir Koru (Tavsiye Edilen)</option>
                                                <option value="no" <?php selected($keep_original_title, 'no'); ?>>Başlığı da Yapay Zekaya Yeniden Yazdır</option>
                                            </select>
                                            <p class="description">"Orijinal Başlığı Birebir Koru" seçildiğinde ürün adı kaynak sitedekiyle aynı kalır; sadece açıklamalar ve Yoast SEO alanları özgünleştirilir.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <div class="dli-card" style="margin-top: 20px;">
                            <div class="dli-card-header">
                                <h2 class="dli-card-title">💰 Fiyat Kâr Marjı & WooCommerce Kuralları</h2>
                            </div>
                            <div class="dli-card-body">
                                <table class="form-table" role="presentation">
                                    <tr>
                                        <th scope="row"><label for="dli_margin_percent">Yüzde Kâr Marjı (%)</label></th>
                                        <td>
                                            <input type="number" step="0.1" min="0" id="dli_margin_percent" name="dli_margin_percent" value="<?php echo esc_attr($margin_percent); ?>" class="small-text"> %
                                            <p class="description">Örnek: 20 yazarsanız ürün fiyatına %20 eklenir (100 TL &rarr; 120 TL).</p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><label for="dli_margin_fixed">Sabit Tutar Ekleme (+TL)</label></th>
                                        <td>
                                            <input type="number" step="0.5" min="0" id="dli_margin_fixed" name="dli_margin_fixed" value="<?php echo esc_attr($margin_fixed); ?>" class="small-text"> TL
                                            <p class="description">Örnek: 50 yazarsanız her ürün fiyatının üzerine doğrudan 50 TL eklenir.</p>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><label for="dli_product_status">Ürün Durumu</label></th>
                                        <td>
                                            <select id="dli_product_status" name="dli_product_status">
                                                <option value="publish" <?php selected($product_status, 'publish'); ?>>Doğrudan Yayında (Publish)</option>
                                                <option value="draft" <?php selected($product_status, 'draft'); ?>>Taslak (Draft - İnceleme için)</option>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><label for="dli_duplicate_mode">Mevcut Ürün Davranışı</label></th>
                                        <td>
                                            <select id="dli_duplicate_mode" name="dli_duplicate_mode">
                                                <option value="update" <?php selected($duplicate_mode, 'update'); ?>>Mevcut Ürünü Güncelle (Fiyat & İçerik)</option>
                                                <option value="skip" <?php selected($duplicate_mode, 'skip'); ?>>Mevcut Ürünü Atla (Tekrar Ekleme)</option>
                                            </select>
                                        </td>
                                    </tr>

                                    <tr>
                                        <th scope="row"><label for="dli_is_virtual">Sanal Ürün Olarak İşaretle</label></th>
                                        <td>
                                            <select id="dli_is_virtual" name="dli_is_virtual">
                                                <option value="yes" <?php selected($is_virtual, 'yes'); ?>>Evet (Kargo gerektirmez - Dijital Lisans)</option>
                                                <option value="no" <?php selected($is_virtual, 'no'); ?>>Hayır</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>

                                <div class="dli-form-footer">
                                    <button type="submit" id="dli-btn-save-settings" class="button button-primary button-hero">
                                        <span class="dashicons dashicons-saved"></span> Ayarları Kaydet
                                    </button>
                                    <span id="dli-settings-save-notice" style="margin-left: 12px; font-weight: 600;"></span>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Sitemap Tarama
     */
    public static function ajax_fetch_sitemap() {
        check_ajax_referer('dli_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Yetkisiz erişim.'));
        }

        $products = DLI_Scraper::fetch_sitemap_products();

        if (empty($products)) {
            wp_send_json_error(array('message' => 'Sitemap üzerinde ürün bulunamadı veya siteye erişilemedi.'));
        }

        wp_send_json_success(array(
            'count'    => count($products),
            'products' => $products,
        ));
    }

    /**
     * AJAX: Tek Ürün İçe Aktarma
     */
    public static function ajax_import_single() {
        check_ajax_referer('dli_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Yetkisiz erişim.'));
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        if (empty($url)) {
            wp_send_json_error(array('message' => 'URL adresi boş bırakılamaz.'));
        }

        $result = DLI_Importer::process_product_url($url);

        if (!$result['success']) {
            wp_send_json_error($result);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: OpenRouter Test
     */
    public static function ajax_test_openrouter() {
        check_ajax_referer('dli_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Yetkisiz erişim.'));
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        $model   = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : '';

        $test = DLI_AI_Rewriter::test_connection($api_key, $model);
        if ($test['success']) {
            wp_send_json_success($test);
        } else {
            wp_send_json_error($test);
        }
    }

    /**
     * AJAX: Ayarları Kaydet
     */
    public static function ajax_save_settings() {
        check_ajax_referer('dli_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Yetkisiz erişim.'));
        }

        $api_key             = isset($_POST['dli_openrouter_api_key']) ? sanitize_text_field($_POST['dli_openrouter_api_key']) : '';
        $model               = isset($_POST['dli_openrouter_model']) ? sanitize_text_field($_POST['dli_openrouter_model']) : 'google/gemini-2.0-flash-001';
        $enable_ai           = isset($_POST['dli_enable_ai']) && $_POST['dli_enable_ai'] === 'yes' ? 'yes' : 'no';
        $keep_original_title = isset($_POST['dli_keep_original_title']) && $_POST['dli_keep_original_title'] === 'no' ? 'no' : 'yes';
        $margin_fixed        = isset($_POST['dli_margin_fixed']) ? (float)$_POST['dli_margin_fixed'] : 0;
        $margin_percent      = isset($_POST['dli_margin_percent']) ? (float)$_POST['dli_margin_percent'] : 0;
        $product_status      = isset($_POST['dli_product_status']) && $_POST['dli_product_status'] === 'draft' ? 'draft' : 'publish';
        $duplicate_mode      = isset($_POST['dli_duplicate_mode']) && $_POST['dli_duplicate_mode'] === 'skip' ? 'skip' : 'update';
        $is_virtual          = isset($_POST['dli_is_virtual']) && $_POST['dli_is_virtual'] === 'no' ? 'no' : 'yes';

        update_option('dli_openrouter_api_key', $api_key);
        update_option('dli_openrouter_model', $model);
        update_option('dli_enable_ai', $enable_ai);
        update_option('dli_keep_original_title', $keep_original_title);
        update_option('dli_margin_fixed', $margin_fixed);
        update_option('dli_margin_percent', $margin_percent);
        update_option('dli_product_status', $product_status);
        update_option('dli_duplicate_mode', $duplicate_mode);
        update_option('dli_is_virtual', $is_virtual);

        wp_send_json_success(array('message' => 'Ayarlar başarıyla kaydedildi!'));
    }
}
