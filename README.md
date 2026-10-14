# Erusoft WooCommerce Trendyol Eklentisi

Trendyol pazaryeri siparişlerini WooCommerce'e **izole** olarak içeri aktaran WordPress eklentisi.

Düşen siparişler 5 dakikalık cron ile çekilir, kendi özel statülerinde durur ve mağazanızın
normal sipariş akışını (stok düşümü, ERP aktarımı, kargo, ödeme, bildirim e-postaları)
**tetiklemez**. Böylece pazaryeri siparişleri sitenizin kendi siparişleriyle karışmadan,
kontrollü bir kulvarda ilerler.

- **Gereksinimler:** WordPress 5.0+, WooCommerce, PHP 7.2+
- **Sürüm:** 1.2.0
- **HPOS:** uyumlu (High-Performance Order Storage)

---

## Neden "izole"?

Bir pazaryeri siparişi WooCommerce'e normal yolla kaydedilirse, `woocommerce_new_order`
kancasına bağlı ne varsa çalışır: stok düşer, ERP'ye sipariş gider, müşteriye e-posta
atılır, kargo etiketi üretilir. Oysa bu işlerin hepsini pazaryeri kendi tarafında zaten
yapmıştır. Sonuç: çift stok düşümü, mükerrer ERP kaydı, müşteriye alakasız e-posta.

Bu eklenti siparişi kaydederken `woocommerce_new_order` dinleyicilerini **o kayıt boyunca
geçici olarak devre dışı bırakır ve hemen geri yükler.** Hiçbir başka eklenti veya tema
dosyası değiştirilmez. Sipariş `trendyol-yeni` statüsünde durur; statü geçişine bağlı
eklentiler de tetiklenmez.

---

## Kurulum

1. `eru-trendyol` klasörünü `wp-content/plugins/` altına kopyalayın.
2. WordPress yönetim panelinde **Eklentiler → Erusoft WooCommerce Trendyol Eklentisi → Etkinleştir**.
   Etkinleştirmede iki tablo oluşturulur ve cron zamanlanır.
3. **WooCommerce → Trendyol** ekranından ayarları girin (aşağıya bakın).
4. Ayarları kaydettikten sonra **Aktif** kutusunu işaretleyin. Cron yalnız aktifken çalışır.

> Eklenti varsayılan olarak **kapalı** gelir. Kimlik bilgilerinizi girmeden aktif etmeyin.

### Trendyol tarafında ihtiyacınız olanlar

Trendyol Satıcı Paneli → **Hesap Bilgilerim → Entegrasyon Bilgileri**:

| Alan | Nerede bulunur |
|---|---|
| Satıcı ID (Seller ID) | Entegrasyon Bilgileri sayfasının üstünde |
| API Key | Aynı sayfa — "API Key" |
| API Secret | Aynı sayfa — "API Secret" |

---

## Ayarlar

**WooCommerce → Trendyol**

| Ayar | Açıklama |
|---|---|
| **Aktif** | Cron'u açar/kapatır. Kapalıyken hiçbir istek atılmaz. |
| **Test modu** | Açıkken siparişler çekilir ama WooCommerce'e **yazılmaz** — sadece loglanır. Kimlikleri doğrulamak için önce bununla deneyin. |
| **Satıcı ID** | Trendyol Seller ID. Yalnız rakam. |
| **API Key / API Secret** | Basic auth kimlikleri. |
| **Entegrasyon referansı** | `User-Agent` header'ında `{seller_id} - {ref}` olarak gider. Boş bırakılırsa `SelfIntegration` kullanılır. |
| **Çekilecek statüler** | Trendyol'dan hangi paket statülerinin alınacağı (virgülle). Varsayılan yeterlidir. |
| **Geriye dönük tarama (saat)** | Her cron turunda kaç saat geriye bakılacağı. Varsayılan 24. |
| **Stok gönderimi** | Açıkken WooCommerce stok değişimleri Trendyol'a gönderilir. **Varsayılan kapalı** — açmadan önce ürün eşleşmesini doğrulayın. |

---

## Sipariş statüleri

Eklenti kendi statülerini kaydeder; `pazaryeri` siparişleri bu kulvarda ilerler.

| Statü | Anlamı |
|---|---|
| `trendyol-yeni` | Sipariş içeri alındı, işlenmeyi bekliyor |
| `trendyol-alindi` | Sipariş alındı olarak işaretlendi |
| `trendyol-hazir` | ERP'ye / hazırlığa aktarıldı |
| `trendyol-kargo` | Kargoya verildi |
| `trendyol-teslim` | Teslim edildi |
| `trendyol-iptal` | İptal |
| `trendyol-iade` | İade |

Statüler `wc-` önekiyle kayıtlıdır (`wc-trendyol-yeni` vb.), WooCommerce sipariş listesinde
filtrelenebilir.

---

## Ürün eşleşmesi

Trendyol'daki `stockCode` alanı WooCommerce **SKU**'suyla eşleştirilir. Eşleşme bulunamazsa
sipariş yine içeri alınır ama kalem "eşleşmedi" olarak işaretlenir ve log'a düşer —
sipariş sessizce kaybolmaz.

Stok gönderimini açacaksanız önce SKU'ların birebir tuttuğundan emin olun.

---

## Cron

| Hook | Sıklık | İş |
|---|---|---|
| `eru_trendyol_sync_orders` | 5 dakika | Sipariş çekme |
| `eru_trendyol_stock_sync_cron` | — | Stok gönderimi (ayar açıksa) |
| `eru_trendyol_product_match_cron` | — | Ürün eşleştirme önbelleği |

WordPress cron ziyarete bağlıdır. Düşük trafikli sitelerde gerçek bir sistem cron'u kurmanız
önerilir:

```
*/5 * * * * curl -s https://siteniz.com/wp-cron.php?doing_wp_cron > /dev/null
```

ve `wp-config.php` içine:

```php
define( 'DISABLE_WP_CRON', true );
```

---

## Veritabanı tabloları

| Tablo | İçerik |
|---|---|
| `{prefix}eru_trendyol_orders` | Çekilen paketler, WooCommerce sipariş eşleşmesi, hata durumu |
| `{prefix}eru_trendyol_log` | İşlem günlüğü |

Şema `ERU_TRENDYOL_DB_VERSION` ile sürümlenir ve `dbDelta` ile göç ettirilir.

---

## Entegrasyon noktaları (hook'lar)

Eklenti stok senkronu için kendi başına bir şey yapmaz; bunun yerine iki action yayar.
Kendi ERP/CRM entegrasyonunuzu bunlara bağlayabilirsiniz. Her ikisi de sipariş başına
**yalnız bir kez** tetiklenir (meta guard).

```php
// Sipariş içeri alındığında — stok REZERVE/DÜŞ
add_action( 'eru_trendyol_crm_positive', function ( $order_id ) {
    // $order_id: WooCommerce sipariş ID'si
}, 10, 1 );

// Sipariş ERP'ye aktarıldığında ya da iptal/iade olduğunda — stok DENGELE
add_action( 'eru_trendyol_crm_negative', function ( $order_id, $new_status ) {
    // $new_status: geçilen statü (trendyol-hazir / trendyol-iptal / trendyol-iade)
}, 10, 2 );
```

Sipariş meta'sında `_eru_trendyol_*` anahtarları bulunur (paket ID, sipariş no, fatura tipi,
VKN/TCKN, vergi dairesi, kargo takip no, komisyon oranı ve tutarı). Kendi entegrasyonunuz
bunları okuyabilir.

---

## Sorun giderme

**Sipariş gelmiyor**
- Ayarlarda *Aktif* işaretli mi, *Test modu* kapalı mı?
- **WooCommerce → Trendyol → Günlük** sekmesine bakın; kimlik hatası (401) burada görünür.
- Geriye dönük tarama saatini geçici olarak yükseltip tek tur deneyin.

**401 / yetkisiz**
- Seller ID yalnız rakam olmalı.
- API Key/Secret'ı panelden yeniden kopyalayın; baş/son boşluk kalmasın.

**Sipariş geldi ama kalemler eşleşmedi**
- Trendyol `stockCode` ile WooCommerce SKU birebir aynı olmalı (büyük/küçük harf dahil).

**Cron çalışmıyor**
- `wp cron event list | grep eru_trendyol` ile zamanlanmış mı bakın.
- Eklentiyi pasifleştirip yeniden aktif etmek cron'u yeniden kurar.

---

## Lisans

GPL-2.0-or-later — WordPress eklentileri için standart lisans.
