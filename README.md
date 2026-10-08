# Dijital Lisans WooCommerce Ürün Aktarıcı & AI SEO Özgünleştirici

Bu eklenti, **dijitallisans.com.tr** üzerindeki dijital ürünleri, kategorileri ve fiyatları analiz ederek hedef WordPress & WooCommerce mağazanıza otomatik aktarır. Aktarım sırasında **OpenRouter AI** kullanarak ürün başlıklarını, açıklamalarını özgünleştirir ve **Yoast SEO** meta alanlarını (SEO Başlığı, Meta Açıklaması ve Odak Anahtar Kelimesi) otomatik doldurur.

Kullanıcı isteği doğrultusunda **ürün görselleri indirilmez/alınmaz**, bu sayede içe aktarma süreci ultra hızlı çalışır ve sunucu kaynaklarınızı tüketmez.

---

## 🚀 Temel Özellikler

- 🌐 **Sitemap Tarama & Toplu İçe Aktarım:** `dijitallisans.com.tr` üzerindeki tüm ürünleri sitemap üzerinden anında çeker, liste halinde sunar ve seçilenleri tek tıkla aktarır.
- ⚡ **Tek Ürün Hızlı Aktarım:** İstediğiniz herhangi bir dijital lisans ürününün linkini yapıştırarak saniyeler içinde mağazanıza ekleyin.
- 🤖 **OpenRouter Yapay Zeka Desteği:**
  - İstediğiniz OpenRouter modelini seçebilme (örn: `openai/gpt-4o-mini`, `google/gemini-2.0-flash-001`, `anthropic/claude-3.5-sonnet` vb.).
  - Ürün başlığı, detaylı HTML açıklaması ve kısa ürün özeti profesyonel e-ticaret diliyle tamamen özgünleştirilir.
- 🎯 **Yoast SEO Otomasyonu:**
  - `_yoast_wpseo_title`: SEO odaklı ürün başlığı
  - `_yoast_wpseo_metadesc`: Arama motorları için optimize edilmiş meta açıklama
  - `_yoast_wpseo_focuskw`: En uygun odak anahtar kelime
- 📁 **Hiyerarşik Kategori Eşleme:** Kaynak sitedeki kategori yapısını (örn: `Office Lisansları > Office 365`) hedef mağazanızda otomatik açar ve ürünü bağlar.
- 💰 **Kâr Marjı / Fiyat Kuralı:** Orijinal fiyatların üzerine sabit tutar (+TL) veya yüzde (% kâr) ekleme.
- 🚫 **Sıfır Görsel Yükü (Görsel İndirilmez):** Sunucu kotanızı korumak için ürün resimleri kesinlikle indirilmez ve kaydedilmez.
- 📊 **Canlı AJAX İlerleme Çubuğu & Log:** Sayfa yenilenmeden tek tek işleme ve anlık durum bildirimi.

---

## 📦 Kurulum

1. `dijitallisans-woo-importer.zip` dosyasını indirin.
2. WordPress Yönetici Paneli > **Eklentiler > Yeni Ekle > Eklenti Yükle** adımından zip dosyasını yükleyin ve etkinleştirin.
3. Sol menüde beliren **"Dijital Lisans Aktarıcı"** sekmesine gidin.
4. **Ayarlar** sekmesinden OpenRouter API Key'inizi girin ve kâr marjınızı belirleyin.
5. İster tek ürün linki yapıştırın, ister Sitemap sekmesinden ürünleri seçip toplu olarak aktarın!

---

## 🛠️ Mimari ve Dosya Yapısı

```
dijitallisans-woo-importer/
├── .gitignore
├── README.md
├── dijitallisans-importer.php       # Eklenti ana yükleme dosyası
├── includes/
│   ├── class-dli-scraper.php        # Sitemap ve ürün veri ayrıştırıcısı (Scraper)
│   ├── class-dli-ai-rewriter.php    # OpenRouter API entegrasyonu ve Yoast SEO üreticisi
│   ├── class-dli-importer.php       # WooCommerce ürün oluşturucu & kategori bağlayıcı
│   └── class-dli-admin.php          # Admin paneli yönetimi ve AJAX uç noktaları
└── assets/
    ├── css/
    │   └── admin-style.css          # Modern dashboard stilleri
    └── js/
        └── admin-script.js          # Canlı AJAX ilerleme ve olay dinleyicileri
```

---

## 📄 Lisans
GPL-2.0+
