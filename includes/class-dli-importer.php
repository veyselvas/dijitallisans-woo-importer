<?php
/**
 * Dijital Lisans WooCommerce İçe Aktarma Sınıfı
 *
 * Kazınan ve AI ile özgünleştirilen ürünleri WooCommerce ve Yoast SEO verileriyle kaydeder.
 * (Ürün görselleri kesinlikle indirilmez / kaydedilmez).
 *
 * @package Dijitallisans_Woo_Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class DLI_Importer {

    /**
     * Tek bir ürün URL'sini işler: Kazır -> AI ile Özgünleştirir -> Fiyat Hesabını Yapar -> WooCommerce'e Kaydeder
     *
     * @param string $source_url Dijitallisans ürün sayfası URL'si
     * @return array Sonuç durumu, ürün ID'si ve log mesajı
     */
    public static function process_product_url($source_url) {
        // 1. Kazıma (Scraping)
        $scraped = DLI_Scraper::scrape_product($source_url);
        if (is_wp_error($scraped)) {
            return array(
                'success' => false,
                'message' => 'Sayfa verisi alınamadı: ' . $scraped->get_error_message(),
                'url'     => $source_url,
            );
        }

        if (empty($scraped['title'])) {
            return array(
                'success' => false,
                'message' => 'Ürün başlığı tespit edilemedi.',
                'url'     => $source_url,
            );
        }

        // 2. OpenRouter AI ile Özgünleştirme & Yoast SEO Üretimi
        $enable_ai = get_option('dli_enable_ai', 'yes');
        $ai_log = '';
        if ($enable_ai === 'yes') {
            $rewritten = DLI_AI_Rewriter::rewrite_product($scraped);
            if (!empty($rewritten['ai_error'])) {
                $ai_log = ' (AI Uyarısı: ' . $rewritten['ai_error'] . ')';
            } else {
                $ai_log = ' [AI ve Yoast SEO Özgünleştirildi]';
            }
        } else {
            $rewritten = array(
                'title'             => $scraped['title'],
                'description'       => $scraped['description'],
                'short_description' => $scraped['short_description'],
                'yoast_title'       => $scraped['title'],
                'yoast_metadesc'    => wp_strip_all_tags($scraped['short_description']),
                'yoast_focuskw'     => '',
                'ai_applied'        => false,
            );
            $ai_log = ' [Orijinal İçerik Korundu]';
        }

        // 3. Fiyat Hesaplama & Kâr Marjı
        $prices = self::calculate_prices($scraped['regular_price'], $scraped['sale_price']);

        // 4. Kategori Eşleme ve Hiyerarşik Oluşturma
        $cat_ids = self::sync_categories($scraped['categories']);

        // 5. Mevcut Ürün Kontrolü (URL meta veya SKU üzerinden)
        $existing_id = self::find_existing_product($source_url, $scraped['sku']);
        $duplicate_mode = get_option('dli_duplicate_mode', 'update'); // update veya skip

        if ($existing_id && $duplicate_mode === 'skip') {
            return array(
                'success'    => true,
                'action'     => 'skipped',
                'product_id' => $existing_id,
                'title'      => $rewritten['title'],
                'url'        => $source_url,
                'edit_url'   => get_edit_post_link($existing_id, 'raw'),
                'message'    => 'Zaten mevcut olduğu için atlandı (ID: ' . $existing_id . ')',
            );
        }

        // 6. WooCommerce Ürünü Oluştur / Güncelle
        $post_status = get_option('dli_product_status', 'publish');
        $is_virtual  = get_option('dli_is_virtual', 'yes');

        $post_data = array(
            'post_title'   => $rewritten['title'],
            'post_content' => $rewritten['description'],
            'post_excerpt' => $rewritten['short_description'],
            'post_status'  => $post_status,
            'post_type'    => 'product',
        );

        if ($existing_id) {
            $post_data['ID'] = $existing_id;
            $product_id = wp_update_post($post_data);
            $action = 'updated';
        } else {
            $product_id = wp_insert_post($post_data);
            $action = 'created';
        }

        if (is_wp_error($product_id) || !$product_id) {
            return array(
                'success' => false,
                'message' => 'WooCommerce ürünü kaydedilemedi.',
                'url'     => $source_url,
            );
        }

        // 6.1 Ürün Türü, Fiyatlar ve Varyasyonlar
        $is_variable_product = !empty($scraped['is_variable']) && !empty($scraped['variations']);

        if ($is_variable_product) {
            // Varyasyonlu Ürün Türünü Belirle
            wp_set_object_terms($product_id, 'variable', 'product_type');

            // Nitelikleri (Attributes) Ana Ürüne Ata
            $product_attributes = array();
            $position = 0;
            foreach ($scraped['attributes'] as $attr_slug => $attr_data) {
                $clean_attr_key = sanitize_title($attr_slug);
                $product_attributes[$clean_attr_key] = array(
                    'name'         => $attr_data['name'],
                    'value'        => implode(' | ', $attr_data['options']),
                    'position'     => $position++,
                    'is_visible'   => 1,
                    'is_variation' => 1,
                    'is_taxonomy'  => 0,
                );
            }
            update_post_meta($product_id, '_product_attributes', $product_attributes);

            // Mevcut eski varyasyonları temizle (tekrarı önlemek için)
            $old_variations = get_posts(array(
                'post_type'   => 'product_variation',
                'post_status' => array('publish', 'draft', 'any'),
                'numberposts' => -1,
                'post_parent' => $product_id,
                'fields'      => 'ids',
            ));
            if (!empty($old_variations)) {
                foreach ($old_variations as $old_v_id) {
                    wp_delete_post($old_v_id, true);
                }
            }

            // Her bir varyasyonu (Stokta olan ve olmayan TÜMÜ) oluştur
            $min_var_price = PHP_INT_MAX;
            $max_var_price = 0;

            foreach ($scraped['variations'] as $v) {
                $var_prices = self::calculate_prices($v['regular_price'], $v['sale_price']);
                $v_active_price = $var_prices['sale_price'] > 0 ? $var_prices['sale_price'] : $var_prices['regular_price'];

                if ($v_active_price > 0 && $v_active_price < $min_var_price) {
                    $min_var_price = $v_active_price;
                }
                if ($v_active_price > $max_var_price) {
                    $max_var_price = $v_active_price;
                }

                $var_title = $rewritten['title'] . (!empty($v['attributes']) ? ' - ' . implode(', ', $v['attributes']) : '');

                $var_id = wp_insert_post(array(
                    'post_title'   => $var_title,
                    'post_content' => '',
                    'post_status'  => 'publish',
                    'post_type'    => 'product_variation',
                    'post_parent'  => $product_id,
                ));

                if ($var_id && !is_wp_error($var_id)) {
                    // Fiyatlar
                    update_post_meta($var_id, '_regular_price', $var_prices['regular_price'] > 0 ? (string)$var_prices['regular_price'] : '');
                    if ($var_prices['sale_price'] > 0 && $var_prices['sale_price'] < $var_prices['regular_price']) {
                        update_post_meta($var_id, '_sale_price', (string)$var_prices['sale_price']);
                        update_post_meta($var_id, '_price', (string)$var_prices['sale_price']);
                    } else {
                        delete_post_meta($var_id, '_sale_price');
                        update_post_meta($var_id, '_price', $var_prices['regular_price'] > 0 ? (string)$var_prices['regular_price'] : '');
                    }

                    // SKU
                    if (!empty($v['sku'])) {
                        update_post_meta($var_id, '_sku', sanitize_text_field($v['sku']));
                    }

                    // Stok durumu (kullanıcı isteği: orijinal durum aktarılır)
                    $v_stock = !empty($v['is_in_stock']) ? 'instock' : 'outofstock';
                    update_post_meta($var_id, '_manage_stock', 'no');
                    update_post_meta($var_id, '_stock_status', $v_stock);
                    update_post_meta($var_id, '_virtual', $is_virtual === 'yes' ? 'yes' : 'no');
                    update_post_meta($var_id, '_downloadable', 'no');

                    // Nitelik eşlemesi (attribute_...)
                    foreach ($v['attributes'] as $attr_k => $attr_v) {
                        $clean_k = sanitize_title($attr_k);
                        if (strpos($clean_k, 'attribute_') !== 0) {
                            $clean_k = 'attribute_' . $clean_k;
                        }
                        update_post_meta($var_id, $clean_k, sanitize_text_field($attr_v));
                    }
                }
            }

            // Ana ürün için fiyat sınırlarını güncelle
            if ($min_var_price !== PHP_INT_MAX && $min_var_price > 0) {
                update_post_meta($product_id, '_price', (string)$min_var_price);
                update_post_meta($product_id, '_min_variation_price', (string)$min_var_price);
                update_post_meta($product_id, '_max_variation_price', (string)$max_var_price);
            }
        } else {
            // Basit Ürün Türünü Belirle
            wp_set_object_terms($product_id, 'simple', 'product_type');

            update_post_meta($product_id, '_regular_price', $prices['regular_price'] > 0 ? (string)$prices['regular_price'] : '');
            if ($prices['sale_price'] > 0 && $prices['sale_price'] < $prices['regular_price']) {
                update_post_meta($product_id, '_sale_price', (string)$prices['sale_price']);
                update_post_meta($product_id, '_price', (string)$prices['sale_price']);
            } else {
                delete_post_meta($product_id, '_sale_price');
                update_post_meta($product_id, '_price', $prices['regular_price'] > 0 ? (string)$prices['regular_price'] : '');
            }
        }

        if (!empty($scraped['sku'])) {
            update_post_meta($product_id, '_sku', sanitize_text_field($scraped['sku']));
        }

        update_post_meta($product_id, '_manage_stock', 'no');
        update_post_meta($product_id, '_stock_status', 'instock');
        update_post_meta($product_id, '_virtual', $is_virtual === 'yes' ? 'yes' : 'no');
        update_post_meta($product_id, '_downloadable', 'no');
        update_post_meta($product_id, '_dli_source_url', esc_url_raw($source_url));
        update_post_meta($product_id, '_dli_imported_at', current_time('mysql'));

        // Kategorileri ata
        if (!empty($cat_ids)) {
            wp_set_object_terms($product_id, $cat_ids, 'product_cat');
        }

        // 7. Yoast SEO Meta Alanlarını Kaydet
        if (!empty($rewritten['yoast_title'])) {
            update_post_meta($product_id, '_yoast_wpseo_title', $rewritten['yoast_title']);
        }
        if (!empty($rewritten['yoast_metadesc'])) {
            update_post_meta($product_id, '_yoast_wpseo_metadesc', $rewritten['yoast_metadesc']);
        }
        if (!empty($rewritten['yoast_focuskw'])) {
            update_post_meta($product_id, '_yoast_wpseo_focuskw', $rewritten['yoast_focuskw']);
        }

        // Rank Math yüklü siteler için de uyumluluk sağlayalım
        if (!empty($rewritten['yoast_title'])) {
            update_post_meta($product_id, 'rank_math_title', $rewritten['yoast_title']);
        }
        if (!empty($rewritten['yoast_metadesc'])) {
            update_post_meta($product_id, 'rank_math_description', $rewritten['yoast_metadesc']);
        }
        if (!empty($rewritten['yoast_focuskw'])) {
            update_post_meta($product_id, 'rank_math_focus_keyword', $rewritten['yoast_focuskw']);
        }

        // (NOT: Kullanıcı kuralı gereği görsel indirme / media attachment KESİNLİKLE YAPILMADI).

        // Başarılı Sonuç
        $final_price = $prices['sale_price'] > 0 ? $prices['sale_price'] : $prices['regular_price'];
        return array(
            'success'    => true,
            'action'     => $action,
            'product_id' => $product_id,
            'title'      => $rewritten['title'],
            'price'      => number_format((float)$final_price, 2, ',', '.') . ' TL',
            'url'        => $source_url,
            'edit_url'   => get_edit_post_link($product_id, 'raw'),
            'message'    => ($action === 'created' ? 'Yeni ürün eklendi' : 'Mevcut ürün güncellendi') . $ai_log,
        );
    }

    /**
     * Kâr marjı kurallarına göre fiyatları hesaplar
     */
    private static function calculate_prices($regular, $sale) {
        $margin_fixed   = (float)get_option('dli_margin_fixed', 0);
        $margin_percent = (float)get_option('dli_margin_percent', 0);

        $calc_price = function($p) use ($margin_fixed, $margin_percent) {
            if ($p <= 0) {
                return 0;
            }
            $new_p = $p;
            if ($margin_percent > 0) {
                $new_p += ($p * ($margin_percent / 100));
            }
            if ($margin_fixed > 0) {
                $new_p += $margin_fixed;
            }
            return round($new_p, 2);
        };

        $new_regular = $calc_price((float)$regular);
        $new_sale    = $sale > 0 ? $calc_price((float)$sale) : 0;

        return array(
            'regular_price' => $new_regular,
            'sale_price'    => $new_sale,
        );
    }

    /**
     * Kategorileri hiyerarşik (Ana Kategori > Alt Kategori) olarak oluşturur ve ID listesini döndürür
     */
    private static function sync_categories($category_path) {
        if (empty($category_path) || !is_array($category_path)) {
            return array();
        }

        $term_ids = array();
        $parent_id = 0;

        foreach ($category_path as $cat_name) {
            $cat_name = trim($cat_name);
            if (empty($cat_name)) {
                continue;
            }

            // Kategori mevcut mu kontrol et
            $existing_term = term_exists($cat_name, 'product_cat', $parent_id);

            if ($existing_term !== 0 && $existing_term !== null) {
                $term_id = is_array($existing_term) ? (int)$existing_term['term_id'] : (int)$existing_term;
            } else {
                // Yeni kategori oluştur
                $inserted = wp_insert_term($cat_name, 'product_cat', array(
                    'parent' => $parent_id,
                    'slug'   => sanitize_title($cat_name),
                ));

                if (!is_wp_error($inserted)) {
                    $term_id = (int)$inserted['term_id'];
                } else {
                    // Slug çakışması durumunda mevcut olanı al
                    $term = get_term_by('name', $cat_name, 'product_cat');
                    $term_id = $term ? $term->term_id : 0;
                }
            }

            if ($term_id > 0) {
                $term_ids[] = $term_id;
                $parent_id  = $term_id; // Bir sonraki seviye için parent yap
            }
        }

        return array_unique($term_ids);
    }

    /**
     * Önceden aktarılmış ürünü bulur
     */
    private static function find_existing_product($url, $sku) {
        global $wpdb;

        // Önce kaynak URL'ye göre ara
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_dli_source_url' AND meta_value = %s LIMIT 1",
            esc_url_raw($url)
        ));

        if ($post_id) {
            return (int)$post_id;
        }

        // SKU ile ara
        if (!empty($sku)) {
            $sku_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
                sanitize_text_field($sku)
            ));
            if ($sku_id) {
                return (int)$sku_id;
            }
        }

        return false;
    }
}
