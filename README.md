# YakNet API Client Services (`yaknet/api-client-services`)

[![Latest Version on Packagist](https://img.shields.io/packagist/v/yaknet/api-client-services.svg?style=flat-square)](https://packagist.org/packages/yaknet/api-client-services)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![PHP Version Require](https://img.shields.io/packagist/php-v/yaknet/api-client-services.svg?style=flat-square)](https://packagist.org/packages/yaknet/api-client-services)

**YakNet Developer Console** (`https://developer-console.yakhub.com.tr`) üzerindeki tüm bulut servisleri için **Discovery JSON** şemalarından otomatik derlenen resmi, modüler ve yüksek performanslı PHP SDK kütüphanesi.

> **Not:** OAuth 2.0 / SSO kullanıcı girişi için `yaknet/y-auth`, Altcha PoW bot koruması ve WAF bileşenleri için `yaknet/yacaptcha` paketleri bağımsız olarak kullanılmaya devam eder. Bu paket tüm **YakNet Bulut / Konsol REST API** servislerini tek çatı altında sunar.

---

## Desteklenen Bulut Servisleri (24 Servis, 240+ Üretilmiş Sınıf)

| Servis Sınıfı | Açıklama | Kaynaklar (Resources) |
| :--- | :--- | :--- |
| `YakNet\Api\Storage` | Cloud Storage kova (bucket) ve nesne depolama | `$storage->buckets`, `$storage->objects` |
| `YakNet\Api\Datastore` | Koleksiyon ve JSON belge tabanlı NoSQL veritabanı | `$datastore->collections`, `$datastore->documents` |
| `YakNet\Api\SecretManager` | AES-256 şifreli gizli anahtar kasası ve sürüm yönetimi | `$secrets->secrets`, `$secrets->versions` |
| `YakNet\Api\PubSub` | Asenkron konu (topic) yayıncılığı, Pull/Push kuyrukları ve ACK | `$pubsub->topics`, `$pubsub->subscriptions` |
| `YakNet\Api\CloudKv` | Düşük gecikmeli anahtar-değer deposu ve Remote Config | `$kv->entries` |
| `YakNet\Api\SmartLinks` | Akıllı kısa bağlantılar, dinamik QR kod ve tıklama analitiği | `$links->links`, `$links->qrcodes`, `$links->analytics` |
| `YakNet\Api\Monitoring` | HTTP/HTTPS Uptime izleyicileri, canlı yoklama ve SLA metrikleri | `$monitoring->uptimeChecks` |
| `YakNet\Api\CloudScheduler` | Yönetilen Cron HTTP görevleri ve anlık tetikleme | `$scheduler->jobs` |
| `YakNet\Api\Logging` | Merkezi denetim ve özel uygulama günlükleri okuma/yazma | `$logging->entries` |
| `YakNet\Api\CloudWebhooks` | Eventarc Webhook yönetimi, özel olay tetikleme ve imza doğrulama | `$webhooks->endpoints`, `$webhooks->events`, `$webhooks->deliveries` |
| `YakNet\Api\ResourceManager` | Proje meta verileri, kaynak kotaları ve servis etkinleştirme | `$rm->projects`, `$rm->services` |
| `YakNet\Api\CloudDns` | Yönetilen DNS bölgeleri, A/AAAA/CNAME/MX kayıt setleri, BIND ve yayılma testi | `$dns->zones`, `$dns->records`, `$dns->propagation` |
| `YakNet\Api\CloudKms` | Proje izoleli XChaCha20-Poly1305 AEAD şifreleme, HMAC imzalama ve CSPRNG | `$kms->cryptoKeys`, `$kms->random` |
| `YakNet\Api\Dlp` | Hassas veri keşfi (TCKN, TR IBAN, Kredi Kartı) ve KVKK maskeleme | `$dlp->content` |
| `YakNet\Api\CloudSecurity` | Harici WAF yük denetimi, SSRF/WebRisk URL kontrolü ve parola entropi testi | `$sec->threats`, `$sec->passwords` |
| `YakNet\Api\Totp` | RFC 6238 iki aşamalı doğrulama (2FA) kaydı, SVG QR ve TOTP kod doğrulama | `$totp->authenticator` |
| `YakNet\Api\DomainIntel` | Geçici e-posta ve MX doğrulaması, DNS sorgulama ve X.509 SSL denetimi | `$intel->email`, `$intel->dns`, `$intel->ssl` |
| `YakNet\Api\CloudMedia` | Vektörel SVG QR kodları, deterministik Identicon/Initials avatar ve rozetler | `$media->qr`, `$media->avatars`, `$media->badges` |
| `YakNet\Api\DeviceIntel` | User-Agent ve dil başlıklarından cihaz, işletim sistemi, tarayıcı ve bot tespiti | `$device->userAgent` |
| `YakNet\Api\TextUtils` | Türkçe metin/okuma süresi analizi, güvenli Markdown HTML, slug ve Link Unfurl | `$text->text`, `$text->web` |
| `YakNet\Api\CloudTime` | IANA zaman dilimi dönüştürücü, yaz saati (DST) ve Cron önizleme motoru | `$time->timezones`, `$time->cron` |
| `YakNet\Api\Yakopedia` | Yakopedia bilgi bankası Türkçe ansiklopedi arama, makale özeti ve rastgele madde | `$yakopedia->articles` |
| `YakNet\Api\CloudArmor` | Proof-of-Work (PoW) bot koruması challenge üretimi, jeton doğrulama ve WAF | `$armor->challenges`, `$armor->waf` |
| `YakNet\Api\Authenticator` | Mobil YakNet Authenticator gerçek zamanlı 2FA giriş onayı (Push Prompt) dağıtımı | `$auth->prompts` |

---

## Kurulum

```bash
composer require yaknet/api-client-services
```

---

## Hızlı Başlangıç

### 1. İstemci (Client) Kimlik Doğrulaması

YakNet Developer Console → **API'ler ve Servisler** ekranından aldığınız Proje API Anahtarını (`yk_live_...`) veya OAuth 2.0 `Client ID` / `Client Secret` bilgilerinizi tanımlayın:

```php
use YakNet\Api\Client;

$client = new Client();
$client->setApplicationName('BenimUygulamam');
$client->setApiKey('yk_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

// Alternatif: OAuth 2.0 İstemci Kimliği & Gizli Anahtarı ile:
// $client->setClientCredentials('CLIENT_ID', 'CLIENT_SECRET');
```

### 2. Cloud Storage Kullanımı

```php
use YakNet\Api\Storage;
use YakNet\Api\Storage\CreateBucketRequest;
use YakNet\Api\Storage\PutObjectRequest;

$storage = new Storage($client);

// Yeni kova oluşturma
$storage->buckets->insert(new CreateBucketRequest([
    'name' => 'faturalar-2026',
    'location' => 'tr-istanbul1',
    'storage_class' => 'STANDARD',
]));

// Nesne yükleme
$storage->objects->insert('faturalar-2026', new PutObjectRequest([
    'key' => 'kasim/fatura-1042.json',
    'content' => json_encode(['tutar' => 1450.00, 'para_birimi' => 'TRY']),
    'mime_type' => 'application/json',
]));

// Kovaları listeleme (Collection doğrudan foreach ile dönülebilir)
foreach ($storage->buckets->listBuckets() as $bucket) {
    echo $bucket->getUri() . ' (' . $bucket->getObjectsCount() . " nesne)\n";
}
```

### 3. Secret Manager Kullanımı

```php
use YakNet\Api\SecretManager;

$secrets = new SecretManager($client);
$version = $secrets->secrets->access('PAYMENT_API_SECRET');

$apiSecret = $version->getPayload();
```

### 4. Cloud Datastore (NoSQL) Kullanımı

```php
use YakNet\Api\Datastore;
use YakNet\Api\Datastore\UpsertDocumentRequest;

$datastore = new Datastore($client);

$datastore->documents->upsert('kullanicilar', 'usr_1001', new UpsertDocumentRequest([
    'data' => [
        'ad' => 'Ayşe Yılmaz',
        'paket' => 'kurumsal',
        'aktif' => true,
    ],
]));

$belge = $datastore->documents->get('kullanicilar', 'usr_1001');
print_r($belge->getData());
```

### 5. Cloud Pub/Sub & Cloud Logging

```php
use YakNet\Api\Logging;
use YakNet\Api\Logging\WriteLogEntryRequest;
use YakNet\Api\PubSub;
use YakNet\Api\PubSub\PublishMessageRequest;

$pubsub = new PubSub($client);
$pubsub->topics->publish('siparis.olustu', new PublishMessageRequest([
    'message' => ['siparis_no' => 'YN-9081'],
    'attributes' => ['kaynak' => 'web'],
]));

$logging = new Logging($client);
$logging->entries->write(new WriteLogEntryRequest([
    'action' => 'checkout.completed',
    'severity' => 'INFO',
    'summary' => 'Sipariş #YN-9081 tamamlandı',
    'details' => ['tutar' => 2400],
]));
```

### 6. Cloud KMS, Cloud DLP & Cloud TOTP (Yardımcı Mikroservisler)

```php
use YakNet\Api\CloudKms;
use YakNet\Api\CloudKms\EncryptRequest;
use YakNet\Api\Dlp;
use YakNet\Api\Dlp\DeidentifyContentRequest;
use YakNet\Api\Totp;
use YakNet\Api\Totp\TotpEnrollRequest;

// Proje izoleli XChaCha20-Poly1305 zarf şifreleme
$kms = new CloudKms($client);
$enc = $kms->cryptoKeys->encrypt('odeme-anahtari', new EncryptRequest([
    'plaintext' => 'TR330006100519786457841326',
]));
$ciphertext = $enc->getCiphertext(); // ykms:v1:...

// KVKK hassas veri maskeleme (TCKN, IBAN, Kredi Kartı, E-Posta)
$dlp = new Dlp($client);
$masked = $dlp->content->deidentify(new DeidentifyContentRequest([
    'text' => 'Müşteri TCKN: 10000000146, E-posta: ayse@yakhub.com.tr',
    'strategy' => 'mask',
]));
echo $masked->getTransformedText();

// RFC 6238 İki Aşamalı Doğrulama (2FA) kaydı ve SVG QR üretimi
$totp = new Totp($client);
$enroll = $totp->authenticator->enroll(new TotpEnrollRequest([
    'account_name' => 'ayse@yakhub.com.tr',
    'issuer' => 'YakDocs',
]));
$qrDataUri = $enroll->getQrDataUri();
```

### 7. Gelen Webhook İmzalarını Doğrulama (`X-YakNet-Signature`)

```php
use YakNet\Api\Webhook\SignatureVerifier;

$rawBody = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_YAKNET_SIGNATURE'] ?? '';

if (!SignatureVerifier::verify($rawBody, $signature, 'whsec_gizli_imza_anahtariniz')) {
    http_response_code(401);
    exit('Geçersiz imza');
}
```

---

## Otomatik Kod Üretimi (Discovery Generator)

Konsola yeni bir API servisi veya metot eklendiğinde tüm `Service`, `Resource`, `Model` ve `Collection` sınıflarını otomatik üretmek için:

```bash
# Yerel discovery/*.json şemalarından üretir:
composer generate

# Doğrudan canlı YakNet Developer Console Discovery API'sinden şemaları çekip üretir:
php bin/generate-services --url=https://developer-console.yakhub.com.tr/api/discovery/v1/apis
```

---

## Test ve Statik Analiz

```bash
composer test
composer analyze
```

## Lisans

**YakNet Bilişim** tarafından geliştirilmiştir ve **MIT Lisansı** ile lisanslanmıştır.
