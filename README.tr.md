# FOSSBilling için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

[Paymos](https://paymos.io/tr) için resmi FOSSBilling modülü ve ödeme ağ geçidi adaptörüdür (`modules/Paymos/` + `library/Payment/Adapter/Paymos/`). Müşterilerin FOSSBilling faturalarını 13 ana ağda dört stablecoin (**USDT**, **USDC**, **USD1**, **DAI**) ve altın destekli **XAUT** ile ödemesini sağlar; onaylanan ödemeleri hem FOSSBilling 0.7 (RedBean) hem de FOSSBilling 0.8+ / 2.0 (Doctrine) üzerinde FOSSBilling’in yerleşik faturalama hattı (`addFunds` + `payInvoiceWithCredits`) aracılığıyla kaydeder.

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video tanıtımı (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- FOSSBilling `0.7.x` veya `0.8+ / 2.0+` (`>=0.7.0 <1.0 || ^2.0`)
- `pdo`, `curl`, `hash`, `json` ve `openssl` uzantıları etkin PHP 8.1+
- **HTTPS** üzerinden çalışan bir FOSSBilling adresi (`config.php` içindeki `url`) — düz HTTP üzerinden bağlantı reddedilir
- `config.php` içinde boş olmayan bir FOSSBilling kurulum `salt` değeri
- Paymos panelinde hedef projenin seçili olduğu bir Paymos hesabı

Kurulum sırasında Composer adımı gerekmez: Paymos PHP SDK (`paymos/php-sdk >= 1.11.0`) `modules/Paymos/vendor/` içinde hazır olarak gelir. Yönetici arayüzü ve ödeme ekranı mesajları altı dilde sunulur: İngilizce, Rusça, Almanca, İspanyolca, Türkçe ve Basitleştirilmiş Çince (`zh-Hans`).

## Kurulum ve bağlantı

1. En güncel sürüm arşivini [GitHub Releases](https://github.com/Paymos-labs/fossbilling/releases/latest) sayfasından indirin.
2. Arşivi FOSSBilling kök dizininize çıkarın — `modules/Paymos/` ve `library/Payment/Adapter/Paymos/` dizinlerini standart konumlarına yerleştirir.
3. FOSSBilling yönetim panelinde **Extensions → Overview** bölümünü açın ve **Paymos** modülünü etkinleştirin.
4. **System → Payment Gateways → New Payment Gateway** ekranını açın, **Paymos** ağ geçidini kurun ve etkinleştirin.
5. Paymos panelinde hedef projenizi açın, ardından FOSSBilling yönetim panelinde **Extensions → Paymos** sayfasına gidin.
6. **Paymos’u bağla** (Connect Paymos) düğmesine tıklayın ve açılan Paymos sekmesinde bağlantıyı onaylayın.

Her kurulum sıfır API anahtarı, API gizli anahtarı, proje ID’si, webhook gizli anahtarı veya cihaz kodu içeren aynı arşivi indirir. Tek bir onay hem **Sandbox** hem de **Canlı** (Live) ortamlarını atomik olarak yapılandırır ve sabit Guest API callback rotasını kaydeder:

```text
https://your-billing.example/index.php?_url=/api/guest/paymos/webhook
```

Bağlantıdan sonra yalnızca gizli olmayan iki tercih bulunur: **Mod** (`Sandbox` veya `Live`) ve **Düğme metni**. Arayüzün hiçbir yerinde elle girilen kimlik bilgisi veya webhook gizli anahtarı alanı yoktur.

## Gizli bilgilerin saklanması

Yapılandırılan Sandbox ve Canlı kimlik bilgileri ile geçici RFC 8628 cihaz akışı durumu, FOSSBilling’in `config.php` dosyasındaki `salt` değerinden HKDF-SHA256 ile türetilen kimlik doğrulamalı AES-256-GCM zarfları (`Paymos\Plugin\AesGcmEnvelope`) içinde mühürlenir. Kayıtlı gizli bilgiler hiçbir zaman HTML/Twig şablonlarına, API yanıtlarına, hata mesajlarına veya günlüklere yazdırılmaz. Kurulum URL’sini değiştirir, bir Ödeme anahtarını iptal eder veya webhook gizli anahtarını yenilerseniz **Paymos’u yeniden bağla** (Reconnect Paymos) ile bağlantıyı yenileyin.

## Ödeme ve fatura yaşam döngüsü

- **Çökmeye dayanıklı oluşturma (`STATUS_CREATING`):** `POST /v1/invoices` çağrısından önce adaptör `paymos_invoice_snapshots` tablosuna bekleyen bir kayıt yazar. Yanıt kaydedilmeden önce ağ çağrısı veya işlem kesilirse, sonraki fatura yüklemesi aynı `external_order_id`, `project_id`, `amount`, `currency` ve `environment` değerleriyle isteği yineleyerek mükerrer fatura üretmeden mevcut faturayı kurtarır.
- **Fatura kalemleri (`products[]`):** Geçerli fatura satırları (`$invoice['lines']` içinden pozitif fiyatlı 1–50 kalem) `Paymos\Plugin\InvoicePresentation` üzerinden normalize edilerek `products[]` içinde gönderilir; geçersiz veya boş satır kümelerinde yalnızca tutar bazlı ödemeye sorunsuz geçiş yapılır.
- **Güvenli durdurmalı yenileme (`InvoiceReplacement`):** Bir fatura toplamı, para birimi, mod veya proje değiştiğinde — ya da ödenmemiş bir Paymos faturasının süresi dolduğunda (`InvoiceRenewal`) — adaptör önce Paymos üzerindeki eski faturayı kapatır. Eski fatura ödenmişse, hâlâ ödenebilir durumdaysa, erişilemiyorsa veya 404 döndürüyorsa yenileme engellenir (`InvoiceReplacementBlockedException`) ve manuel incelemeye alınır; böylece alıcı aynı fatura için iki açık faturaya ödeme yapamaz. `paid_late_noted` olarak işaretlenen faturalar da incelenene kadar yenilemeyi engeller.
- **Otomatik abonelik çekimi yoktur:** Paymos itme tabanlı blokzincir transferleri işler ve otomatik yinelenen çekim yapmaz; FOSSBilling tarafından oluşturulan yenileme faturaları müşteri tarafından tek tek ödenir.

## Webhook’lar ve cron mutabakatı

Gelen webhook’lar `/index.php?_url=/api/guest/paymos/webhook` (`Box\Mod\Paymos\Api\Guest::webhook()`) uç noktasına ulaşır; ham `php://input` gövdesini okur, `MultiEnvironmentWebhookVerifier` ile hem Sandbox hem Canlı ortamda büyük/küçük harfe duyarsız biçimde `X-Webhook-Signature` doğrulaması yapar (çift `v1=` rotasyon desteği, 300 saniyelik yeniden oynatma penceresi), `PdoEventStore` üzerinden atomik tekilleştirme uygular (işlenmiş yinelemeler için `200`, eşzamanlı süren teslimatlar için `409 Conflict`, geçersiz veya süresi dolmuş imzalar için `401`), nihai olayları `GET /v1/invoices/{id}` (`InvoiceReverseVerifier`) ile ters doğrular, üçgen ondalık tutar kontrollerini (`AmountGuard`) uygular ve ödenmiş ya da kesinleşmiş faturaları geri almaya karşı korur. 2xx dışı durum kodları (`401`/`409`/`400`/`500`) doğrudan döndürülür; böylece `Api_Handler` webhook hatalarını hiçbir zaman HTTP `200` olarak yeniden yazmaz.

`invoice.paid_late` kesinlikle yalnızca not olarak işlenir: `paid_late_noted` kaydını yazar ve fatura ödeme durumunu değiştirmeden manuel inceleme notu ekler.

Kaçırılan webhook’lar FOSSBilling cron dinleyicisi (`Box\Mod\Paymos\Service::onAfterAdminCronRun` / `AfterAdminCronRunEvent`) tarafından yakalanır: en fazla 10 dakikada bir çalışacak şekilde son 24 saate ait en fazla 50 ödenmemiş Paymos faturasını aynı doğrulama korumalarından geçirerek mutabık kılar; ayrıca bekleyen ve Paymos panelinden başlatılan iadeleri de senkronize eder.

## İadeler ve fazla ödemeler

Modül tam Paymos iade yaşam döngüsünü (`RefundCoordinator`, `RefundIntentStoreInterface`, `RefundCandidateStoreInterface`, `NativeRefundProjectorInterface` ve `RefundDiscovery`) uygular ve sunucu tarafından hesaplanan her iki modu da destekler:

- **`full`:** Ödenmiş bir faturanın Paymos tarafından kaydedilen doğrulanmış `first_sender` adresine tam iadesi.
- **`overpayment`:** FOSSBilling faturasını ödenmiş durumda tutarak `paid_over` faturasındaki fazla ödenen tutarın iadesi.

İade yetkisine ve geçerli CSRF belirtecine sahip yöneticiler, iadeleri **Invoices → Paymos Refunds** (`/admin/paymos/refunds`) altından veya FOSSBilling 2.0’ın yerleşik `refundPayment()` / `listRefundsForTransaction()` adaptör kancalarıyla yönetir. Keyfi kısmi tutarlar ve elle girilen alıcı adresleri veya ağlar herhangi bir API isteğinden önce reddedilir.

- **Erken muhasebe borcu yazılmaz:** pending, processing veya unknown durumundaki iadeler FOSSBilling’de asla erken muhasebe kaydı oluşturmaz.
- **Doğrulanmış tamamlanma yansıtması:** `GET /v1/refunds/{withdrawal_id}` ve `GET /v1/invoices/{invoice_id}` çağrıları `completed` durumunu doğruladığında (`withdrawal.completed` webhook’u veya cron ile), `full` iadeler FOSSBilling 2.0’ın `Invoice\Service::refundInvoice($invoice, $note, null, ['gateway_returned' => true, 'gateway_refund' => false, 'external_reversal' => [...]])` yöntemiyle tam bir kez yansıtılır (güvenli FOSSBilling 0.7 yedek yoluyla birlikte); `overpayment` iadeleri ise tamamlanma notu kaydeder ve faturayı ödenmiş bırakır.
- **Panel iadelerinin keşfi:** Doğrudan Paymos panelinden veya API üzerinden başlatılan iadeler `RefundDiscovery` ile algılanır, imzalı `GET` okumalarıyla doğrulanır ve yinelenen bir `POST /v1/refunds` isteği göndermeden sisteme yansıtılır.

## Sorun giderme

- **Bağlantı başlamıyor:** `config.php` içindeki `url` değerinin `https://` ile başladığını ve `salt` ayarının yapılandırıldığını doğrulayın.
- **Ödeme sonrasında fatura ödenmemiş görünüyor:** FOSSBilling günlüklerinde `Manual review` kayıtlarını (tutar uyuşmazlığı, ters doğrulama çakışması veya engellenen yenileme) kontrol edin ya da sabit `/index.php?_url=/api/guest/paymos/webhook` adresinin HTTP kimlik doğrulaması ve yönlendirme olmadan herkese açık erişilebilir olduğunu doğrulayın.
- **İade düğmesi yeniden bağlantı gerektiğini söylüyor:** Şifreli kimlik bilgisi zarfına `invoice_refunds_v1` yetkisinin kaydedilmesi için **Extensions → Paymos → Reconnect Paymos** üzerinden kurulumu yeniden bağlayın.

## Bağlantılar

- [Dokümantasyon](https://paymos.io/tr/docs/cms-fossbilling)
- [Kaynak kodu](https://github.com/Paymos-labs/fossbilling)
- [Değişiklik günlüğü](CHANGELOG.md)
- [Destek](mailto:support@paymos.io)
- [Lisans](LICENSE) — MIT License, Copyright (c) 2026 Paymos Labs
