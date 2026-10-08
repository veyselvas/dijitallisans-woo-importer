/**
 * Dijital Lisans WooCommerce Importer - Admin JavaScript
 */

jQuery(document).ready(function ($) {
    'use strict';

    // 1. Sekme Değişimi
    $('.dli-nav-tab').on('click', function (e) {
        e.preventDefault();
        var targetTab = $(this).data('tab');

        $('.dli-nav-tab').removeClass('active');
        $(this).addClass('active');

        $('.dli-tab-content').removeClass('active');
        $('#tab-' + targetTab).addClass('active');

        // URL hash güncelle
        if (history.pushState) {
            history.pushState(null, null, '#tab=' + targetTab);
        }
    });

    // Hash varsa otomatik sekmeyi aç
    var hash = window.location.hash;
    if (hash && hash.indexOf('tab=') !== -1) {
        var tabName = hash.replace('#tab=', '');
        var $tabLink = $('.dli-nav-tab[data-tab="' + tabName + '"]');
        if ($tabLink.length) {
            $tabLink.trigger('click');
        }
    }

    // 2. Model Seçim Listesi Yönetimi
    $('#dli_openrouter_model_select').on('change', function () {
        var val = $(this).val();
        if (val === 'custom') {
            $('#dli_custom_model_wrapper').slideDown(150);
            $('#dli_openrouter_model').focus();
        } else {
            $('#dli_custom_model_wrapper').slideUp(150);
            $('#dli_openrouter_model').val(val);
        }
    });

    // 3. OpenRouter Bağlantı Testi
    $('#dli-btn-test-ai').on('click', function () {
        var $btn = $(this);
        var apiKey = $('#dli_openrouter_api_key').val().trim();
        var model = $('#dli_openrouter_model').val().trim();
        var $result = $('#dli-ai-test-result');

        if (!apiKey) {
            $result.html('<span style="color:#dc2626;">Lütfen önce API anahtarınızı girin.</span>');
            return;
        }

        $btn.prop('disabled', true).text('Test ediliyor...');
        $result.html('<span style="color:#64748b;">OpenRouter ile iletişim kuruluyor...</span>');

        $.ajax({
            url: dli_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'dli_test_openrouter',
                nonce: dli_ajax.nonce,
                api_key: apiKey,
                model: model
            },
            success: function (res) {
                if (res.success) {
                    $result.html('<span style="color:#16a34a; font-weight:600;">✓ ' + res.data.message + '</span>');
                } else {
                    $result.html('<span style="color:#dc2626; font-weight:600;">✗ ' + res.data.message + '</span>');
                }
            },
            error: function () {
                $result.html('<span style="color:#dc2626;">Sunucu bağlantı hatası oluştu.</span>');
            },
            complete: function () {
                $btn.prop('disabled', false).text('Bağlantıyı Test Et');
            }
        });
    });

    // 4. Ayarları Kaydetme
    $('#dli-form-settings').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#dli-btn-save-settings');
        var $notice = $('#dli-settings-save-notice');
        var formData = $(this).serialize();

        $btn.prop('disabled', true);
        $notice.html('<span style="color:#64748b;">Kaydediliyor...</span>');

        $.ajax({
            url: dli_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: formData + '&action=dli_save_settings&nonce=' + dli_ajax.nonce,
            success: function (res) {
                if (res.success) {
                    $notice.html('<span style="color:#16a34a;">✓ ' + res.data.message + '</span>');
                    setTimeout(function () { $notice.fadeOut(300, function () { $(this).html('').show(); }); }, 3000);
                } else {
                    $notice.html('<span style="color:#dc2626;">✗ ' + (res.data.message || 'Kaydedilemedi.') + '</span>');
                }
            },
            error: function () {
                $notice.html('<span style="color:#dc2626;">Sunucu hatası oluştu.</span>');
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });

    // 5. Tek Ürün Hızlı Aktarma
    $('#dli-form-single-import').on('submit', function (e) {
        e.preventDefault();
        var $btn = $('#dli-btn-single-submit');
        var url = $('#dli-single-url').val().trim();
        var $resultBox = $('#dli-single-result');

        if (!url) return;

        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Aktarılıyor...');
        $resultBox.show().removeClass('dli-result-success dli-result-error')
            .html('<div style="color:#64748b;"><span class="spinner is-active" style="float:none; margin:0 8px 0 0;"></span> Ürün verisi çekiliyor, OpenRouter AI ile özgünleştiriliyor ve WooCommerce\'e kaydediliyor...</div>');

        $.ajax({
            url: dli_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'dli_import_single',
                nonce: dli_ajax.nonce,
                url: url
            },
            success: function (res) {
                if (res.success) {
                    var data = res.data;
                    var html = '<div class="dli-result-success" style="padding:15px; border-radius:6px;">' +
                        '<h3 style="margin:0 0 8px; color:#16a34a;">✓ Ürün Başarıyla Aktarıldı!</h3>' +
                        '<p style="margin:0 0 6px;"><strong>Ürün Adı:</strong> ' + data.title + '</p>' +
                        '<p style="margin:0 0 6px;"><strong>Hesaplanan Fiyat:</strong> ' + data.price + '</p>' +
                        '<p style="margin:0 0 10px;"><strong>Durum:</strong> ' + data.message + '</p>' +
                        '<div style="margin-top:12px; display:flex; gap:10px;">' +
                            '<a href="' + data.edit_url + '" target="_blank" class="button button-primary">WooCommerce\'de Düzenle</a>' +
                        '</div>' +
                    '</div>';
                    $resultBox.html(html);
                    $('#dli-single-url').val('');
                } else {
                    $resultBox.html('<div class="dli-result-error" style="padding:15px; border-radius:6px; color:#dc2626;">' +
                        '<strong>Hata:</strong> ' + (res.data.message || 'Ürün aktarılamadı.') +
                    '</div>');
                }
            },
            error: function () {
                $resultBox.html('<div class="dli-result-error" style="padding:15px; border-radius:6px; color:#dc2626;">' +
                    '<strong>Bağlantı Hatası:</strong> Sunucu yanıt vermedi veya zaman aşımına uğradı.' +
                '</div>');
            },
            complete: function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Şimdi Aktar');
            }
        });
    });

    // 6. Sitemap Tarama
    var sitemapProducts = [];

    $('#dli-btn-scan-sitemap').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> Sitemap Taranıyor...');

        $('#dli-sitemap-empty').hide();
        $('#dli-products-tbody').empty();

        $.ajax({
            url: dli_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'dli_fetch_sitemap',
                nonce: dli_ajax.nonce
            },
            success: function (res) {
                if (res.success && res.data.products) {
                    sitemapProducts = res.data.products;
                    renderProductsTable(sitemapProducts);
                    $('#dli-table-controls').slideDown(200);
                    $('#dli-products-table').show();
                } else {
                    $('#dli-sitemap-empty').show().find('p').text(res.data.message || 'Sitemap taramasında hata oluştu.');
                }
            },
            error: function () {
                $('#dli-sitemap-empty').show().find('p').text('Sitemap taranırken bağlantı hatası oluştu.');
            },
            complete: function () {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Sitemap\'ten Ürünleri Getir');
            }
        });
    });

    function renderProductsTable(products) {
        var html = '';
        $.each(products, function (i, p) {
            html += '<tr data-url="' + p.url + '" class="dli-row">' +
                '<td><input type="checkbox" class="dli-row-cb" value="' + p.url + '"></td>' +
                '<td class="dli-row-title"><strong>' + p.title + '</strong></td>' +
                '<td class="dli-row-url"><a href="' + p.url + '" target="_blank" rel="noopener" style="font-size:12px; color:#64748b;">' + p.url + '</a></td>' +
                '<td class="dli-row-status"><span class="dli-badge-idle" style="color:#64748b; font-size:12px;">Beklemede</span></td>' +
                '<td style="text-align:right;">' +
                    '<button type="button" class="button button-small dli-btn-row-import" data-url="' + p.url + '">Aktar</button>' +
                '</td>' +
            '</tr>';
        });
        $('#dli-products-tbody').html(html);
        updateSelectedCount();
    }

    // Arama Kutusu Filtresi
    $('#dli-search-box').on('keyup', function () {
        var q = $(this).val().toLowerCase();
        $('#dli-products-tbody tr').each(function () {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(q) !== -1);
        });
    });

    // Checkbox Seçim Yönetimi
    $(document).on('change', '#dli-select-all, #dli-th-select-all', function () {
        var isChecked = $(this).is(':checked');
        $('#dli-select-all, #dli-th-select-all').prop('checked', isChecked);
        $('#dli-products-tbody tr:visible .dli-row-cb').prop('checked', isChecked);
        updateSelectedCount();
    });

    $(document).on('change', '.dli-row-cb', function () {
        updateSelectedCount();
    });

    function updateSelectedCount() {
        var checkedCount = $('.dli-row-cb:checked').length;
        $('#dli-count-selected').text(checkedCount);
        $('#dli-btn-start-import').prop('disabled', checkedCount === 0);
    }

    // Tablo İçindeki Tekil "Aktar" Butonu
    $(document).on('click', '.dli-btn-row-import', function () {
        var $btn = $(this);
        var url = $btn.data('url');
        var $row = $btn.closest('tr');
        var $status = $row.find('.dli-row-status');

        $btn.prop('disabled', true).text('...');
        $status.html('<span style="color:#2563eb;">Aktarılıyor...</span>');

        $.ajax({
            url: dli_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'dli_import_single',
                nonce: dli_ajax.nonce,
                url: url
            },
            success: function (res) {
                if (res.success) {
                    $status.html('<span style="color:#16a34a; font-weight:600;">✓ Tamamlandı</span>');
                    $btn.text('Tekrar').prop('disabled', false);
                } else {
                    $status.html('<span style="color:#dc2626;" title="' + (res.data.message || '') + '">✗ Hata</span>');
                    $btn.text('Tekrar').prop('disabled', false);
                }
            },
            error: function () {
                $status.html('<span style="color:#dc2626;">✗ Bağlantı Hatası</span>');
                $btn.text('Tekrar').prop('disabled', false);
            }
        });
    });

    // 7. Toplu İçe Aktarma Kuyruğu
    var importQueue = [];
    var isCancelled = false;
    var totalItems = 0;
    var doneItems = 0;
    var errorItems = 0;

    $('#dli-btn-start-import').on('click', function () {
        importQueue = [];
        $('.dli-row-cb:checked').each(function () {
            importQueue.push($(this).val());
        });

        if (importQueue.length === 0) return;

        totalItems = importQueue.length;
        doneItems = 0;
        errorItems = 0;
        isCancelled = false;

        // UI Hazırla
        $('#dli-progress-box').slideDown(200);
        $('#dli-console').slideDown(200);
        $('#dli-stat-total').text(totalItems);
        $('#dli-stat-done').text('0');
        $('#dli-stat-error').text('0');
        updateProgressBar(0);

        $('#dli-btn-start-import').prop('disabled', true);
        $('#dli-btn-scan-sitemap').prop('disabled', true);
        $('#dli-btn-stop-import').show().prop('disabled', false).text('İşlemi Durdur');

        addLog('info', 'Toplam ' + totalItems + ' ürün için içe aktarma işlemi başlatıldı...');
        processNextInQueue();
    });

    $('#dli-btn-stop-import').on('click', function () {
        isCancelled = true;
        $(this).prop('disabled', true).text('Durduruluyor...');
        addLog('warn', 'İşlem kullanıcı tarafından durduruldu.');
    });

    $('#dli-console-clear').on('click', function (e) {
        e.preventDefault();
        $('#dli-console-logs').empty();
    });

    function processNextInQueue() {
        if (isCancelled || importQueue.length === 0) {
            finishImportQueue();
            return;
        }

        var currentUrl = importQueue.shift();
        var $row = $('tr[data-url="' + currentUrl + '"]');
        var $status = $row.find('.dli-row-status');

        $status.html('<span style="color:#2563eb;">İşleniyor...</span>');
        $('#dli-progress-status-text').text('Aktarılıyor: ' + currentUrl);

        $.ajax({
            url: dli_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'dli_import_single',
                nonce: dli_ajax.nonce,
                url: currentUrl
            },
            success: function (res) {
                if (res.success) {
                    doneItems++;
                    $('#dli-stat-done').text(doneItems);
                    $status.html('<span style="color:#16a34a; font-weight:600;">✓ Tamamlandı</span>');
                    addLog('success', '[' + (doneItems + errorItems) + '/' + totalItems + '] Eklendi: ' + res.data.title + ' (' + res.data.price + ') ' + (res.data.message || ''));
                } else {
                    errorItems++;
                    $('#dli-stat-error').text(errorItems);
                    $status.html('<span style="color:#dc2626;" title="' + (res.data.message || '') + '">✗ Hata</span>');
                    addLog('error', '[' + (doneItems + errorItems) + '/' + totalItems + '] Hata: ' + currentUrl + ' - ' + (res.data.message || 'Bilinmeyen hata'));
                }
            },
            error: function () {
                errorItems++;
                $('#dli-stat-error').text(errorItems);
                $status.html('<span style="color:#dc2626;">✗ Sunucu Hatası</span>');
                addLog('error', '[' + (doneItems + errorItems) + '/' + totalItems + '] Sunucu bağlantı hatası: ' + currentUrl);
            },
            complete: function () {
                var processed = doneItems + errorItems;
                var pct = Math.round((processed / totalItems) * 100);
                updateProgressBar(pct);

                // Sıradaki ürüne geç (küçük 300ms gecikme ile sunucuyu rahatlat)
                setTimeout(processNextInQueue, 300);
            }
        });
    }

    function updateProgressBar(percentage) {
        $('#dli-progress-percentage').text('%' + percentage);
        $('#dli-progress-bar-fill').css('width', percentage + '%');
    }

    function finishImportQueue() {
        $('#dli-btn-start-import').prop('disabled', false);
        $('#dli-btn-scan-sitemap').prop('disabled', false);
        $('#dli-btn-stop-import').hide();

        if (isCancelled) {
            $('#dli-progress-status-text').text('İşlem durduruldu.');
            addLog('warn', 'Aktarım durduruldu. Başarılı: ' + doneItems + ', Hatalı: ' + errorItems);
        } else {
            $('#dli-progress-status-text').text('Tüm işlemler tamamlandı!');
            addLog('info', 'Tüm ürünler işlendi! Toplam: ' + totalItems + ', Başarılı: ' + doneItems + ', Hatalı: ' + errorItems);
        }
    }

    function addLog(type, message) {
        var time = new Date().toLocaleTimeString();
        var $item = $('<div class="dli-log-item ' + type + '">[' + time + '] ' + escapeHtml(message) + '</div>');
        var $body = $('#dli-console-logs');
        $body.append($item);
        $body.scrollTop($body[0].scrollHeight);
    }

    function escapeHtml(text) {
        if (!text) return '';
        return $('<div>').text(text).html();
    }
});
