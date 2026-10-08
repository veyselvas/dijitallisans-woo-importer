<?php
/**
 * Dijital Lisans OpenRouter AI ve Yoast SEO Özgünleştirici Sınıfı
 *
 * OpenRouter API kullanarak ürün başlığı, açıklaması, kısa açıklaması ve Yoast SEO meta alanlarını üretir.
 *
 * @package Dijitallisans_Woo_Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class DLI_AI_Rewriter {

    const OPENROUTER_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Ürünü OpenRouter AI ile özgünleştirir
     *
     * @param array $product_data Ham ürün verileri
     * @return array Özgünleştirilmiş başlık, açıklamalar ve Yoast SEO verileri
     */
    public static function rewrite_product($product_data) {
        $api_key = get_option('dli_openrouter_api_key', '');
        $model   = get_option('dli_openrouter_model', 'google/gemini-2.0-flash-001');

        // Varsayılan geri dönüş (AI kapalı veya key girilmemişse orijinali koru)
        $result = array(
            'title'             => $product_data['title'],
            'description'       => $product_data['description'],
            'short_description' => $product_data['short_description'],
            'yoast_title'       => $product_data['title'],
            'yoast_metadesc'    => wp_strip_all_tags($product_data['short_description']),
            'yoast_focuskw'     => '',
            'ai_applied'        => false,
            'ai_error'          => '',
        );

        // Eğer kısa açıklama boşsa orijinal açıklamadan 150 karakter al
        if (empty($result['yoast_metadesc'])) {
            $plain = wp_strip_all_tags($product_data['description']);
            $result['yoast_metadesc'] = mb_substr($plain, 0, 155, 'UTF-8');
        }

        if (empty($api_key)) {
            $result['ai_error'] = __('OpenRouter API anahtarı ayarlanmamış, orijinal içerik kullanıldı.', 'dijitallisans-importer');
            return $result;
        }

        // Prompt hazırlığı
        $categories_str = is_array($product_data['categories']) ? implode(' > ', $product_data['categories']) : '';
        $raw_desc = wp_strip_all_tags($product_data['description']);
        // Token tasarrufu ve hız için maksimum 3000 karaktere kırp
        if (mb_strlen($raw_desc, 'UTF-8') > 3000) {
            $raw_desc = mb_substr($raw_desc, 0, 3000, 'UTF-8') . '...';
        }

        $keep_original_title = get_option('dli_keep_original_title', 'yes');

        $system_prompt = "Sen profesyonel bir e-ticaret metin yazarı ve Yoast SEO uzmanısın.
Sana verilen dijital lisans veya yazılım ürününün bilgilerini inceleyip, arama motorlarında yüksek sıralama alacak, %100 özgün, ikna edici, profesyonel ve modern bir dille açıklamaları yeniden yazacaksın.

KURALLAR:
1. 'title': " . ($keep_original_title === 'yes' ? "Orijinal ürün başlığını AYNEN koru, kesinlikle değiştirme." : "Özgün, çekici ve SEO uyumlu ürün başlığı yaz.") . "
2. 'short_description': 1-2 cümlelik vurucu, özgün özet ürün tanıtımı.
3. 'description': Zengin HTML etiketleri (<h3>, <p>, <ul>, <li>, <strong>) kullanarak yapılandırılmış, özellikler, anında teslimat, aktivasyon kolaylığı ve avantajları içeren tamamen özgün ve detaylı açıklama.
4. 'yoast_title': Yoast SEO için optimize edilmiş arama başlığı (Maksimum 60 karakter).
5. 'yoast_metadesc': Yoast SEO için tıklama oranını artıran meta açıklaması (Maksimum 150-155 karakter).
6. 'yoast_focuskw': En uygun tek veya 2-3 kelimelik odak anahtar kelime (örn: 'Office 365 Pro Plus', 'Windows 11 Pro Key').
7. Yanıtını KESİNLİKLE sadece saf ve geçerli bir JSON formatında döndür. Açıklama metni, markdown harici kod veya selamlama yazma.";

        $user_prompt = "ÜRÜN BİLGİLERİ:
Orijinal Başlık: " . $product_data['title'] . "
Kategori: " . $categories_str . "
Orijinal Açıklama / Özellikler: " . $raw_desc . "

Lütfen sadece şu JSON formatında cevap ver:
{
  \"title\": \"" . addslashes($product_data['title']) . "\",
  \"short_description\": \"...\",
  \"description\": \"...\",
  \"yoast_title\": \"...\",
  \"yoast_metadesc\": \"...\",
  \"yoast_focuskw\": \"...\"
}";

        $payload = array(
            'model'       => $model,
            'messages'    => array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user', 'content' => $user_prompt),
            ),
            'temperature' => 0.6,
        );

        $response = wp_remote_post(self::OPENROUTER_ENDPOINT, array(
            'timeout'     => 45,
            'headers'     => array(
                'Authorization' => 'Bearer ' . trim($api_key),
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo('name') . ' - DijitalLisans Importer',
            ),
            'body'        => wp_json_encode($payload),
            'sslverify'   => false,
        ));

        if (is_wp_error($response)) {
            $result['ai_error'] = 'API Bağlantı Hatası: ' . $response->get_error_message();
            return $result;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err_data = json_decode($body, true);
            $err_msg = isset($err_data['error']['message']) ? $err_data['error']['message'] : 'HTTP ' . $code;
            $result['ai_error'] = 'OpenRouter Hatası: ' . $err_msg;
            return $result;
        }

        $data = json_decode($body, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            $result['ai_error'] = 'API geçersiz yanıt formatı döndürdü.';
            return $result;
        }

        $content_text = trim($data['choices'][0]['message']['content']);

        // Markdown kod blokları varsa temizle (```json ... ```)
        $clean_json = preg_replace('/^```(?:json)?\s*/i', '', $content_text);
        $clean_json = preg_replace('/\s*```$/', '', $clean_json);
        $clean_json = trim($clean_json);

        $parsed = json_decode($clean_json, true);
        if (!$parsed || !is_array($parsed)) {
            // JSON parse edilemediyse
            $result['ai_error'] = 'Yapay zeka çıktısı JSON olarak ayrıştırılamadı.';
            return $result;
        }

        // Başarılı ayrıştırma
        if ($keep_original_title === 'yes') {
            // Kullanıcı isteği: Başlık her zaman orijinal kalır
            $result['title'] = $product_data['title'];
        } elseif (!empty($parsed['title'])) {
            $result['title'] = sanitize_text_field($parsed['title']);
        }

        if (!empty($parsed['short_description'])) {
            $result['short_description'] = wp_kses_post($parsed['short_description']);
        }
        if (!empty($parsed['description'])) {
            $result['description'] = wp_kses_post($parsed['description']);
        }
        if (!empty($parsed['yoast_title'])) {
            $result['yoast_title'] = sanitize_text_field($parsed['yoast_title']);
        }
        if (!empty($parsed['yoast_metadesc'])) {
            $result['yoast_metadesc'] = sanitize_text_field($parsed['yoast_metadesc']);
        }
        if (!empty($parsed['yoast_focuskw'])) {
            $result['yoast_focuskw'] = sanitize_text_field($parsed['yoast_focuskw']);
        }

        $result['ai_applied'] = true;
        return $result;
    }

    /**
     * OpenRouter API Bağlantısını ve Modelini Test Eder
     *
     * @param string $api_key API Anahtarı
     * @param string $model Model Kodu
     * @return array Sonuç durumu ve mesajı
     */
    public static function test_connection($api_key, $model) {
        $api_key = trim($api_key);
        $api_key = preg_replace('/^Bearer\s+/i', '', $api_key);

        if (empty($api_key)) {
            return array('success' => false, 'message' => 'Lütfen API anahtarı giriniz.');
        }

        $payload = array(
            'model'    => !empty($model) ? $model : 'google/gemini-2.0-flash-001',
            'messages' => array(
                array('role' => 'user', 'content' => 'Merhaba! Bu bir bağlantı testidir. Sadece "BAĞLANTI BAŞARILI" yaz.')
            ),
            'max_tokens' => 20,
        );

        $response = wp_remote_post(self::OPENROUTER_ENDPOINT, array(
            'timeout'   => 15,
            'headers'   => array(
                'Authorization' => 'Bearer ' . trim($api_key),
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
            ),
            'body'      => wp_json_encode($payload),
            'sslverify' => false,
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 200) {
            $data = json_decode($body, true);
            $reply = isset($data['choices'][0]['message']['content']) ? trim($data['choices'][0]['message']['content']) : 'OK';
            return array('success' => true, 'message' => 'Bağlantı Başarılı! Model Yanıtı: ' . $reply);
        } else {
            $err = json_decode($body, true);
            $msg = isset($err['error']['message']) ? $err['error']['message'] : 'Hata Kodu: ' . $code;
            return array('success' => false, 'message' => $msg);
        }
    }
}
