<?php
/**
 * Dijital Lisans Scraper Sınıfı
 *
 * dijitallisans.com.tr üzerinden sitemap ve ürün verilerini çeker ve ayrıştırır.
 *
 * @package Dijitallisans_Woo_Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class DLI_Scraper {

    const BASE_URL = 'https://www.dijitallisans.com.tr';
    const SITEMAP_INDEX = 'https://www.dijitallisans.com.tr/sitemap_index.xml';

    /**
     * Varsayılan HTTP istek başlıkları
     */
    private static function get_http_args() {
        return array(
            'timeout'     => 30,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 WordPress-DLI/1.0',
            'headers'     => array(
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language' => 'tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
            ),
            'sslverify'   => false,
        );
    }

    /**
     * Sitemap üzerinden tüm ürün linklerini toplar
     *
     * @return array Ürün linkleri ve temel bilgileri
     */
    public static function fetch_sitemap_products() {
        $products = array();
        $product_sitemaps = array();

        // 1. Ana sitemap dizinini kontrol et
        $response = wp_remote_get(self::SITEMAP_INDEX, self::get_http_args());

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            if ($xml && isset($xml->sitemap)) {
                foreach ($xml->sitemap as $sm) {
                    $loc = (string)$sm->loc;
                    if (strpos($loc, 'product-sitemap') !== false) {
                        $product_sitemaps[] = $loc;
                    }
                }
            }
        }

        // Eğer sitemap indeksinden bulunamazsa bilinen standart sitemap'leri ekle
        if (empty($product_sitemaps)) {
            $product_sitemaps = array(
                self::BASE_URL . '/product-sitemap1.xml',
                self::BASE_URL . '/product-sitemap2.xml',
            );
        }

        // 2. Her bir ürün sitemap'ini tara
        foreach ($product_sitemaps as $sitemap_url) {
            $sm_res = wp_remote_get($sitemap_url, self::get_http_args());
            if (is_wp_error($sm_res) || wp_remote_retrieve_response_code($sm_res) !== 200) {
                continue;
            }

            $sm_body = wp_remote_retrieve_body($sm_res);
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($sm_body);
            if (!$xml || !isset($xml->url)) {
                continue;
            }

            foreach ($xml->url as $url_node) {
                $loc = trim((string)$url_node->loc);
                $lastmod = isset($url_node->lastmod) ? (string)$url_node->lastmod : '';

                // Filtreleme: Mağaza ana sayfası veya ürün dışı sayfaları atla
                if (empty($loc) || strpos($loc, '/magaza/') !== false || $loc === rtrim(self::BASE_URL, '/') . '/') {
                    continue;
                }

                // URL'den olası başlığı tahmin et (slug temizleme)
                $path = trim(parse_url($loc, PHP_URL_PATH), '/');
                $slug_parts = explode('/', $path);
                $slug = end($slug_parts);
                $clean_title = ucwords(str_replace(array('-', '_'), ' ', $slug));

                $products[] = array(
                    'url'     => $loc,
                    'slug'    => $slug,
                    'title'   => $clean_title,
                    'lastmod' => $lastmod,
                );
            }
        }

        return $products;
    }

    /**
     * Tek bir ürün URL'sini kazır ve tüm verilerini çıkarır
     *
     * @param string $url Ürün sayfası URL'si
     * @return array|WP_Error Ürün verisi veya hata
     */
    public static function scrape_product($url) {
        $url = esc_url_raw(trim($url));
        if (empty($url)) {
            return new WP_Error('invalid_url', __('Geçersiz ürün URL adresi.', 'dijitallisans-importer'));
        }

        $response = wp_remote_get($url, self::get_http_args());
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('http_error', sprintf(__('Sayfa yüklenemedi. HTTP Kodu: %d', 'dijitallisans-importer'), $code));
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return new WP_Error('empty_body', __('Sayfa içeriği boş döndü.', 'dijitallisans-importer'));
        }

        // Veri ayrıştırma
        $product_data = self::parse_product_html($html, $url);
        return $product_data;
    }

    /**
     * HTML içeriğini DOM ve Schema (JSON-LD) ile ayrıştırır
     *
     * @param string $html Ham HTML içeriği
     * @param string $source_url Kaynak link
     * @return array Ayrıştırılmış temiz ürün verileri
     */
    private static function parse_product_html($html, $source_url) {
        $data = array(
            'source_url'        => $source_url,
            'title'             => '',
            'description'       => '',
            'short_description' => '',
            'regular_price'     => '',
            'sale_price'        => '',
            'sku'               => '',
            'categories'        => array(),
        );

        // 1. JSON-LD Şemalarını tara
        $schema_data = self::extract_json_ld($html);
        if (!empty($schema_data)) {
            if (!empty($schema_data['name'])) {
                $data['title'] = html_entity_decode($schema_data['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (!empty($schema_data['description'])) {
                $data['description'] = $schema_data['description'];
            }
            if (!empty($schema_data['sku'])) {
                $data['sku'] = $schema_data['sku'];
            }
            if (!empty($schema_data['category'])) {
                $raw_cat = html_entity_decode($schema_data['category'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $cats = explode('>', $raw_cat);
                foreach ($cats as $c) {
                    $c = trim($c);
                    if (!empty($c)) {
                        $data['categories'][] = $c;
                    }
                }
            }
            if (!empty($schema_data['price'])) {
                $data['regular_price'] = (float)$schema_data['price'];
            }
        }

        // 2. DOM Document & XPath ile derinlemesine incele
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // UTF-8 karakter desteği için HTML-ENTITIES dönüştür
        $encoded_html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        @$dom->loadHTML($encoded_html);
        $xpath = new DOMXPath($dom);

        // Başlık
        if (empty($data['title'])) {
            $title_nodes = $xpath->query("//h1[contains(@class, 'product_title')]");
            if ($title_nodes->length > 0) {
                $data['title'] = trim($title_nodes->item(0)->textContent);
            } else {
                $og_title = $xpath->query("//meta[@property='og:title']/@content");
                if ($og_title->length > 0) {
                    $data['title'] = trim($og_title->item(0)->nodeValue);
                }
            }
        }

        // Kısa Açıklama
        $short_desc_nodes = $xpath->query("//div[contains(@class, 'woocommerce-product-details__short-description')]");
        if ($short_desc_nodes->length > 0) {
            $data['short_description'] = self::get_inner_html($short_desc_nodes->item(0));
        }

        // Detaylı Açıklama (Tab description veya ana açıklama alanı)
        $desc_nodes = $xpath->query("//div[@id='tab-description'] | //div[contains(@class, 'woocommerce-Tabs-panel--description')]");
        if ($desc_nodes->length > 0) {
            $desc_html = self::get_inner_html($desc_nodes->item(0));
            if (!empty(trim(strip_tags($desc_html)))) {
                $data['description'] = $desc_html;
            }
        }

        // Fiyatlar (Regular & Sale Price - Sadece Ana Ürün Özet Alanından)
        $del_price = $xpath->query("//div[contains(@class, 'entry-summary')]//p[contains(@class, 'price')]//del//span[contains(@class, 'woocommerce-Price-amount')] | //div[contains(@class, 'entry-summary')]//span[contains(@class, 'price')]//del//span[contains(@class, 'woocommerce-Price-amount')]");
        $ins_price = $xpath->query("//div[contains(@class, 'entry-summary')]//p[contains(@class, 'price')]//ins//span[contains(@class, 'woocommerce-Price-amount')] | //div[contains(@class, 'entry-summary')]//span[contains(@class, 'price')]//ins//span[contains(@class, 'woocommerce-Price-amount')]");

        if ($del_price->length > 0 && $ins_price->length > 0) {
            $data['regular_price'] = self::clean_price($del_price->item(0)->textContent);
            $data['sale_price']    = self::clean_price($ins_price->item(0)->textContent);
        } else {
            // Tek fiyat
            $single_price = $xpath->query("//div[contains(@class, 'entry-summary')]//p[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount')] | //div[contains(@class, 'entry-summary')]//span[contains(@class, 'price')]//span[contains(@class, 'woocommerce-Price-amount')]");
            if ($single_price->length > 0) {
                $data['regular_price'] = self::clean_price($single_price->item(0)->textContent);
            }
        }

        // Kategoriler (Breadcrumbs üzerinden hiyerarşi)
        if (empty($data['categories'])) {
            $breadcrumb_links = $xpath->query("//nav[contains(@class, 'woocommerce-breadcrumb')]//a");
            $cats = array();
            if ($breadcrumb_links->length > 0) {
                foreach ($breadcrumb_links as $link) {
                    $cat_text = trim($link->textContent);
                    // Anasayfa veya Mağaza gibi kök bağlantıları hariç tut
                    if (!in_array(mb_strtolower($cat_text, 'UTF-8'), array('anasayfa', 'ana sayfa', 'home', 'mağaza', 'magaza'))) {
                        $cats[] = $cat_text;
                    }
                }
            }

            // Ayrıca breadcrumb sonundaki metin düğümü varsa (aktif kategori)
            $last_cat_span = $xpath->query("//nav[contains(@class, 'woocommerce-breadcrumb')]//span[last()]");
            if ($last_cat_span->length > 0) {
                $last_text = trim($last_cat_span->item(0)->textContent);
                if (!empty($last_text) && $last_text !== $data['title'] && !in_array($last_text, $cats)) {
                    $cats[] = $last_text;
                }
            }

            if (!empty($cats)) {
                $data['categories'] = $cats;
            }
        }

        // SKU
        if (empty($data['sku'])) {
            $sku_nodes = $xpath->query("//span[contains(@class, 'sku')]");
            if ($sku_nodes->length > 0) {
                $data['sku'] = trim($sku_nodes->item(0)->textContent);
            }
        }

        // Açıklamaları temizle (reklam, iframe veya gereksiz stiller)
        $data['description']       = self::sanitize_html_content($data['description']);
        $data['short_description'] = self::sanitize_html_content($data['short_description']);

        // 3. Varyasyonları ve Nitelikleri Tara (Stokta olan ve olmayan TÜMÜ)
        $data['is_variable'] = false;
        $data['attributes']  = array();
        $data['variations']  = array();

        // 3.1 Formdaki Nitelikler ve Seçenekler
        if (preg_match_all("/<th[^>]*class=[\x27\"][^\x27\"]*label[^\x27\"]*[\x27\"][^>]*>.*?<label\s+for=[\x27\"]([^\x27\"]+)[\x27\"][^>]*>(.*?)<\/label>.*?<\/th>\s*<td[^>]*>.*?<select[^>]+name=[\x27\"]([^\x27\"]+)[\x27\"][^>]*>(.*?)<\/select>/is", $html, $m)) {
            for ($i = 0; $i < count($m[0]); $i++) {
                $attr_id     = trim($m[1][$i]);
                $attr_name   = trim(strip_tags($m[2][$i]));
                $attr_name   = html_entity_decode($attr_name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $select_name = trim($m[3][$i]);
                $select_html = $m[4][$i];

                $options = array();
                if (preg_match_all("/<option\s+value=[\x27\"]([^\x27\"]+)[\x27\"][^>]*>/is", $select_html, $opt_m)) {
                    foreach ($opt_m[1] as $val) {
                        $val = trim(html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if (!empty($val)) {
                            $options[] = $val;
                        }
                    }
                }

                $data['attributes'][$attr_id] = array(
                    'name'        => $attr_name,
                    'select_name' => $select_name,
                    'options'     => array_values(array_unique($options)),
                );
            }
        }

        // 3.2 data-product_variations JSON ayrıştırma
        if (preg_match('/data-product_variations=[\x27\"](.*?)[\x27\"]/is', $html, $var_m)) {
            $raw_json = html_entity_decode($var_m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $raw_vars = json_decode($raw_json, true);
            if (is_array($raw_vars) && !empty($raw_vars)) {
                $data['is_variable'] = true;
                foreach ($raw_vars as $v) {
                    $reg_p  = !empty($v['display_regular_price']) ? (float)$v['display_regular_price'] : (float)(isset($v['display_price']) ? $v['display_price'] : 0);
                    $sale_p = !empty($v['display_price']) && (float)$v['display_price'] < $reg_p ? (float)$v['display_price'] : 0;

                    $var_atts = array();
                    if (!empty($v['attributes']) && is_array($v['attributes'])) {
                        foreach ($v['attributes'] as $k => $val) {
                            $var_atts[$k] = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        }
                    }

                    $data['variations'][] = array(
                        'sku'           => isset($v['sku']) ? $v['sku'] : '',
                        'regular_price' => $reg_p,
                        'sale_price'    => $sale_p,
                        'is_in_stock'   => !empty($v['is_in_stock']), // Stok durumu
                        'attributes'    => $var_atts,
                    );
                }

                // Varyasyonlu ürünlerde ana fiyatı ilk varyasyondan al
                if (!empty($data['variations'][0])) {
                    $data['regular_price'] = $data['variations'][0]['regular_price'];
                    $data['sale_price']    = $data['variations'][0]['sale_price'];
                }
            }
        }

        return $data;
    }

    /**
     * JSON-LD içerisinden Ürün verisini ayıklar
     */
    private static function extract_json_ld($html) {
        $found = array();
        if (preg_match_all('/<script\s+type=[\'"]application\/ld\+json[\'"][^>]*>(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $json_str) {
                $decoded = json_decode(trim($json_str), true);
                if (!$decoded) {
                    continue;
                }

                $items = array();
                if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                    $items = $decoded['@graph'];
                } else {
                    $items = array($decoded);
                }

                foreach ($items as $item) {
                    $type = isset($item['@type']) ? $item['@type'] : '';
                    if ($type === 'Product' || $type === 'ProductGroup') {
                        $found['name']        = isset($item['name']) ? $item['name'] : '';
                        $found['description'] = isset($item['description']) ? $item['description'] : '';
                        $found['sku']         = isset($item['sku']) ? $item['sku'] : '';
                        $found['category']    = isset($item['category']) ? $item['category'] : '';

                        // Tekil offer veya varyant teklifi
                        if (isset($item['offers'])) {
                            if (isset($item['offers']['price'])) {
                                $found['price'] = $item['offers']['price'];
                            } elseif (is_array($item['offers']) && isset($item['offers'][0]['price'])) {
                                $found['price'] = $item['offers'][0]['price'];
                            }
                        } elseif (isset($item['hasVariant']) && is_array($item['hasVariant'])) {
                            if (isset($item['hasVariant'][0]['offers']['price'])) {
                                $found['price'] = $item['hasVariant'][0]['offers']['price'];
                            }
                        }
                        break 2;
                    }
                }
            }
        }
        return $found;
    }

    /**
     * Fiyat metnini temizleyip float sayıya dönüştürür (Örn: "169,90 ₺" -> 169.90)
     */
    private static function clean_price($price_str) {
        if (empty($price_str)) {
            return 0;
        }
        // Boşluklar, para birimi simgeleri (₺, TL vb.) ve görünmeyen karakterleri temizle
        $cleaned = preg_replace('/[^\d,\.]/', '', $price_str);
        // Türk Lirası virgüllü ondalığı noktaya çevir
        if (strpos($cleaned, ',') !== false && strpos($cleaned, '.') !== false) {
            // 1.250,50 formatı
            $cleaned = str_replace('.', '', $cleaned);
            $cleaned = str_replace(',', '.', $cleaned);
        } elseif (strpos($cleaned, ',') !== false) {
            // 169,90 formatı
            $cleaned = str_replace(',', '.', $cleaned);
        }
        return (float)$cleaned;
    }

    /**
     * DOMNode içindeki HTML içeriğini alır
     */
    private static function get_inner_html($node) {
        $innerHTML = '';
        $children = $node->childNodes;
        foreach ($children as $child) {
            $innerHTML .= $node->ownerDocument->saveHTML($child);
        }
        return trim($innerHTML);
    }

    /**
     * Açıklama HTML'ini zararlı/istenmeyen tag ve betiklerden arındırır
     */
    private static function sanitize_html_content($html) {
        if (empty($html)) {
            return '';
        }
        // Script ve style taglarını tamamen sil
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $html);
        $html = preg_replace('/<form\b[^>]*>(.*?)<\/form>/is', '', $html);
        $html = preg_replace('/<button\b[^>]*>(.*?)<\/button>/is', '', $html);

        // WordPress kses ile izinli HTML etiketlerini filtrele
        $allowed = array(
            'p'      => array('class' => array(), 'style' => array()),
            'h1'     => array('class' => array()),
            'h2'     => array('class' => array()),
            'h3'     => array('class' => array()),
            'h4'     => array('class' => array()),
            'ul'     => array('class' => array()),
            'ol'     => array('class' => array()),
            'li'     => array('class' => array()),
            'strong' => array(),
            'b'      => array(),
            'em'     => array(),
            'i'      => array(),
            'span'   => array('class' => array(), 'style' => array()),
            'table'  => array('class' => array(), 'border' => array()),
            'tr'     => array(),
            'td'     => array('colspan' => array()),
            'th'     => array('colspan' => array()),
            'br'     => array(),
            'hr'     => array(),
        );

        return wp_kses($html, $allowed);
    }
}
