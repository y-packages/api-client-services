<?php

declare(strict_types=1);

namespace YakNet\Api\Tests;

use PHPUnit\Framework\TestCase;
use YakNet\Api\Client;
use YakNet\Api\CloudDns;
use YakNet\Api\CloudDns\CheckPropagationRequest;
use YakNet\Api\CloudDns\CreateRecordRequest;
use YakNet\Api\CloudDns\CreateZoneRequest;
use YakNet\Api\CloudKms;
use YakNet\Api\CloudKms\EncryptRequest;
use YakNet\Api\CloudKv;
use YakNet\Api\CloudKv\PutKvEntryRequest;
use YakNet\Api\CloudMedia;
use YakNet\Api\CloudScheduler;
use YakNet\Api\CloudSecurity;
use YakNet\Api\CloudSecurity\PasswordAuditRequest;
use YakNet\Api\CloudTime;
use YakNet\Api\CloudTime\CronPreviewRequest;
use YakNet\Api\CloudWebhooks;
use YakNet\Api\Datastore;
use YakNet\Api\Datastore\UpsertDocumentRequest;
use YakNet\Api\DeviceIntel;
use YakNet\Api\DeviceIntel\DeviceParseRequest;
use YakNet\Api\Dlp;
use YakNet\Api\Dlp\DlpFinding;
use YakNet\Api\Dlp\InspectContentRequest;
use YakNet\Api\DomainIntel;
use YakNet\Api\DomainIntel\EmailValidateRequest;
use YakNet\Api\Exception\YakNetServiceException;
use YakNet\Api\Generator\DiscoveryGenerator;
use YakNet\Api\Http\MockTransport;
use YakNet\Api\Logging;
use YakNet\Api\Logging\WriteLogEntryRequest;
use YakNet\Api\Monitoring;
use YakNet\Api\PubSub;
use YakNet\Api\PubSub\PublishMessageRequest;
use YakNet\Api\ResourceManager;
use YakNet\Api\SecretManager;
use YakNet\Api\SmartLinks;
use YakNet\Api\SmartLinks\CreateSmartLinkRequest;
use YakNet\Api\Storage;
use YakNet\Api\Storage\Bucket;
use YakNet\Api\Storage\PutObjectRequest;
use YakNet\Api\TextUtils;
use YakNet\Api\TextUtils\SlugifyRequest;
use YakNet\Api\Totp;
use YakNet\Api\Totp\TotpEnrollRequest;
use YakNet\Api\Webhook\SignatureVerifier;

final class ApiClientServicesTest extends TestCase
{
    public function testClientSendsApiKeyAndHydratesStorageCollection(): void
    {
        $mock = (new MockTransport())->queueJson([
            'success' => true,
            'project' => 'yaknet-prod-1001',
            'buckets' => [
                [
                    'name' => 'media-assets',
                    'uri' => 'gs://media-assets',
                    'location' => 'tr-istanbul1',
                    'storage_class' => 'STANDARD',
                    'is_public' => true,
                    'objects_count' => 14,
                ],
                [
                    'name' => 'backups-archive',
                    'uri' => 'gs://backups-archive',
                    'location' => 'tr-ankara1',
                    'storage_class' => 'ARCHIVE',
                    'is_public' => false,
                    'objects_count' => 3,
                ],
            ],
        ]);

        $client = new Client([
            'api_key' => 'yk_live_test_secret_key',
            'application_name' => 'YakDocsPortal',
        ], $mock);

        $storage = new Storage($client);
        $response = $storage->buckets->listBuckets();

        $this->assertTrue($response->getSuccess());
        $this->assertCount(2, $response);

        $iterated = [];
        foreach ($response as $item) {
            $this->assertInstanceOf(Bucket::class, $item);
            $iterated[] = $item->getName();
        }
        $this->assertSame(['media-assets', 'backups-archive'], $iterated);

        $lastReq = $mock->getLastRequest();
        $this->assertNotNull($lastReq);
        $this->assertSame('GET', $lastReq['method']);
        $this->assertSame('https://developer-console.yakhub.com.tr/api/cloud/storage/buckets', $lastReq['url']);
        $this->assertSame('yk_live_test_secret_key', $lastReq['headers']['X-API-Key']);
        $this->assertStringContainsString('YakDocsPortal yaknet-api-php-client/', $lastReq['headers']['User-Agent']);
    }

    public function testStorageReservedPathExpansionPreservesNestedSlashes(): void
    {
        $mock = (new MockTransport())
            ->queueJson([
                'success' => true,
                'bucket' => 'media-assets',
                'key' => 'reports/2026/11/summary.json',
                'size_bytes' => 18,
                'checksum_sha256' => 'abc123',
            ], 201)
            ->queueJson([
                'success' => true,
                'bucket' => 'media-assets',
                'key' => 'reports/2026/11/summary.json',
                'content' => '{"status":"ready"}',
            ]);

        $storage = new Storage(new Client(['apiKey' => 'yk_live_demo'], $mock));

        $req = (new PutObjectRequest())
            ->setKey('reports/2026/11/summary.json')
            ->setContent('{"status":"ready"}')
            ->setMimeType('application/json');

        $putRes = $storage->objects->insert('media-assets', $req);
        $this->assertSame('reports/2026/11/summary.json', $putRes->getKey());

        $getRes = $storage->objects->get('media-assets', 'reports/2026/11/summary.json');
        $this->assertSame('{"status":"ready"}', $getRes->getContent());

        $lastReq = $mock->getLastRequest();
        $this->assertNotNull($lastReq);
        $this->assertSame(
            'https://developer-console.yakhub.com.tr/api/cloud/storage/buckets/media-assets/objects/reports/2026/11/summary.json',
            $lastReq['url']
        );
    }

    public function testDatastoreSecretManagerPubSubAndKvServices(): void
    {
        $mock = (new MockTransport())
            ->queueJson([
                'success' => true,
                'id' => 'user_42',
                'collection' => 'profiles',
                'data' => ['plan' => 'enterprise'],
            ], 201)
            ->queueJson([
                'success' => true,
                'secret' => 'SMTP_PASSWORD',
                'version' => 3,
                'payload' => 's3cr3t-smtp-val',
            ])
            ->queueJson([
                'success' => true,
                'topic' => 'orders.created',
                'message_id' => 'uuid-9001',
                'delivery_status' => 'delivered',
            ], 201)
            ->queueJson([
                'success' => true,
                'key' => 'feature.new_checkout',
                'type' => 'boolean',
                'value' => true,
            ]);

        $client = new Client([], $mock);
        $client->setClientCredentials('client-id-1', 'client-secret-1');

        // 1. Datastore
        $datastore = new Datastore($client);
        $doc = $datastore->documents->upsert('profiles', 'user_42', new UpsertDocumentRequest([
            'data' => ['plan' => 'enterprise'],
        ]));
        $this->assertSame('user_42', $doc->getId());
        $this->assertSame(['plan' => 'enterprise'], $doc->getData());

        // 2. Secret Manager
        $secrets = new SecretManager($client);
        $accessed = $secrets->secrets->access('SMTP_PASSWORD', ['version' => '3']);
        $this->assertSame('s3cr3t-smtp-val', $accessed->getPayload());
        $this->assertSame(3, $accessed->getVersion());

        // 3. Pub/Sub
        $pubsub = new PubSub($client);
        $pubRes = $pubsub->topics->publish('orders.created', new PublishMessageRequest([
            'message' => ['order_id' => 551],
            'attributes' => ['region' => 'TR'],
        ]));
        $this->assertSame('uuid-9001', $pubRes->getMessageId());

        // 4. Cloud KV
        $kv = new CloudKv($client);
        $kvRes = $kv->entries->put('feature.new_checkout', new PutKvEntryRequest([
            'type' => 'boolean',
            'value' => true,
        ]));
        $this->assertTrue($kvRes->getValue());
    }

    public function testSmartLinksMonitoringSchedulerLoggingWebhooksAndResourceManagerInstantiateCleanly(): void
    {
        $mock = (new MockTransport())
            ->queueJson([
                'success' => true,
                'link' => ['slug' => 'kampanya', 'short_url' => 'https://developer-console.yakhub.com.tr/s/kampanya'],
            ], 201)
            ->queueJson([
                'success' => true,
                'entry' => ['id' => 11, 'action' => 'auth.login', 'severity' => 'INFO'],
            ], 201)
            ->queueJson([
                'success' => true,
                'project' => [
                    'id' => 1,
                    'project_number' => '491000007919',
                    'slug' => 'yaknet-core',
                    'usage' => ['buckets_count' => 2],
                ],
            ]);

        $client = new Client(['apiKey' => 'yk_live_all'], $mock);

        $links = new SmartLinks($client);
        $createdLink = $links->links->create(new CreateSmartLinkRequest([
            'destination_url' => 'https://yakhub.com.tr',
            'slug' => 'kampanya',
        ]));
        $this->assertSame('kampanya', $createdLink->getLink()?->getSlug());

        $logging = new Logging($client);
        $logRes = $logging->entries->write(new WriteLogEntryRequest([
            'action' => 'auth.login',
            'summary' => 'Kullanıcı giriş yaptı',
            'severity' => 'INFO',
        ]));
        $this->assertSame(11, $logRes->getEntry()?->getId());

        $rm = new ResourceManager($client);
        $proj = $rm->projects->get();
        $this->assertSame('yaknet-core', $proj->getProject()?->getSlug());
        $this->assertSame(2, $proj->getProject()?->getUsage()?->getBucketsCount());

        // Ensure remaining service classes instantiate with typed resources
        $this->assertSame('monitoring', (new Monitoring($client))->serviceName);
        $this->assertSame('cloudscheduler', (new CloudScheduler($client))->serviceName);
        $this->assertSame('cloudwebhooks', (new CloudWebhooks($client))->serviceName);
    }

    public function testHttpErrorsThrowStructuredYakNetServiceException(): void
    {
        $mock = (new MockTransport())->queueJson([
            'success' => false,
            'error' => "Bu API anahtarı 'secrets' servisi için yetkilendirilmemiş.",
        ], 403);

        $secrets = new SecretManager(new Client(['apiKey' => 'yk_live_restricted'], $mock));

        $this->expectException(YakNetServiceException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessage("Bu API anahtarı 'secrets' servisi için yetkilendirilmemiş.");

        $secrets->secrets->listSecrets();
    }

    public function testWebhookSignatureVerifierValidatesHmacSha256(): void
    {
        $payload = '{"event":"storage.object.finalized","bucket":"assets"}';
        $secret = 'whsec_yaknet_test_secret_99';

        $header = SignatureVerifier::sign($payload, $secret);
        $this->assertTrue(SignatureVerifier::verify($payload, $header, $secret));
        $this->assertFalse(SignatureVerifier::verify($payload, 'sha256=invalid', $secret));
    }

    public function testUtilityMicroservicesKmsDlpTotpSecurityDomainMediaDeviceTextAndTime(): void
    {
        $mock = (new MockTransport())
            ->queueJson([
                'success' => true,
                'project' => 'yaknet-core',
                'key_name' => 'billing-key',
                'algorithm' => 'XCHACHA20-POLY1305-IETF',
                'ciphertext' => 'ykms:v1:encryptedpayload',
            ])
            ->queueJson([
                'success' => true,
                'has_sensitive_data' => true,
                'risk_level' => 'HIGH',
                'findings_count' => 1,
                'findings' => [
                    ['info_type' => 'TR_TCKN', 'likelihood' => 'VERY_LIKELY', 'quote_masked' => '10*******46', 'offset' => 5, 'length' => 11],
                ],
            ])
            ->queueJson([
                'success' => true,
                'secret' => 'JBSWY3DPEHPK3PXP',
                'otpauth_uri' => 'otpauth://totp/YakNet:user@yakhub.com.tr?secret=JBSWY3DPEHPK3PXP',
                'recovery_codes' => ['ABCD-1234'],
            ])
            ->queueJson([
                'success' => true,
                'score' => 4,
                'strength' => 'VERY_STRONG',
                'entropy_bits' => 94.5,
                'charsets' => ['lowercase' => true, 'uppercase' => true, 'digits' => true, 'symbols' => true],
            ])
            ->queueJson([
                'success' => true,
                'email' => 'temp@mailinator.com',
                'is_disposable' => true,
                'verdict' => 'DISPOSABLE',
            ])
            ->queueJson([
                'success' => true,
                'label' => 'build',
                'message' => 'passing',
                'svg' => '<svg></svg>',
            ])
            ->queueJson([
                'success' => true,
                'device_type' => 'desktop',
                'os_name' => 'Windows',
                'browser_name' => 'Chrome',
                'is_desktop' => true,
            ])
            ->queueJson([
                'success' => true,
                'slug' => 'yaknet-gelistirici-konsolu',
                'length' => 26,
            ])
            ->queueJson([
                'success' => true,
                'valid' => true,
                'timezone' => 'Europe/Istanbul',
                'next_runs' => ['2026-11-09T09:00:00+03:00'],
            ]);

        $client = new Client(['apiKey' => 'yk_live_micro'], $mock);

        // 1. Cloud KMS
        $kms = new CloudKms($client);
        $enc = $kms->cryptoKeys->encrypt('billing-key', new EncryptRequest(['plaintext' => 'gizli-veri']));
        $this->assertSame('ykms:v1:encryptedpayload', $enc->getCiphertext());

        // 2. Cloud DLP (Collection iteration over DlpFinding)
        $dlp = new Dlp($client);
        $inspected = $dlp->content->inspect(new InspectContentRequest(['text' => 'TCKN 10000000146']));
        $this->assertTrue($inspected->getHasSensitiveData());
        $this->assertCount(1, $inspected);
        foreach ($inspected as $finding) {
            $this->assertInstanceOf(DlpFinding::class, $finding);
            $this->assertSame('TR_TCKN', $finding->getInfoType());
        }

        // 3. Cloud TOTP
        $totp = new Totp($client);
        $enrolled = $totp->authenticator->enroll(new TotpEnrollRequest(['account_name' => 'user@yakhub.com.tr']));
        $this->assertSame('JBSWY3DPEHPK3PXP', $enrolled->getSecret());

        // 4. Cloud Security
        $security = new CloudSecurity($client);
        $audit = $security->passwords->audit(new PasswordAuditRequest(['password' => 'CokGuclu!Parola#2026_YakNet']));
        $this->assertSame('VERY_STRONG', $audit->getStrength());
        $this->assertTrue($audit->getCharsets()?->getSymbols());

        // 5. Domain Intel
        $domainIntel = new DomainIntel($client);
        $mailRes = $domainIntel->email->validate(new EmailValidateRequest(['email' => 'temp@mailinator.com']));
        $this->assertTrue($mailRes->getIsDisposable());
        $this->assertSame('DISPOSABLE', $mailRes->getVerdict());

        // 6. Cloud Media
        $media = new CloudMedia($client);
        $badge = $media->badges->generate(['label' => 'build', 'message' => 'passing']);
        $this->assertSame('passing', $badge->getMessage());

        // 7. Device Intel
        $deviceIntel = new DeviceIntel($client);
        $parsedUa = $deviceIntel->userAgent->parse(new DeviceParseRequest(['user_agent' => 'Mozilla/5.0 Chrome/130.0']));
        $this->assertSame('desktop', $parsedUa->getDeviceType());

        // 8. TextUtils
        $textUtils = new TextUtils($client);
        $slugRes = $textUtils->text->slugify(new SlugifyRequest(['text' => 'YakNet Geliştirici Konsolu']));
        $this->assertSame('yaknet-gelistirici-konsolu', $slugRes->getSlug());

        // 9. CloudTime
        $cloudTime = new CloudTime($client);
        $cronRes = $cloudTime->cron->preview(new CronPreviewRequest(['expression' => '0 9 * * 1']));
        $this->assertTrue($cronRes->getValid());
        $this->assertCount(1, $cronRes->getNextRuns() ?? []);
    }

    public function testCloudDnsServiceZonesRecordsAndPropagation(): void
    {
        $mock = (new MockTransport())
            ->queueJson([
                'success' => true,
                'zone' => [
                    'id' => '1',
                    'name' => 'yakhub-zone',
                    'dns_name' => 'yakhub.com.tr.',
                    'records_count' => 2,
                ],
            ], 201)
            ->queueJson([
                'success' => true,
                'record' => [
                    'id' => '10',
                    'name' => 'api.yakhub.com.tr.',
                    'type' => 'A',
                    'ttl' => 300,
                    'rrdatas' => ['185.199.108.153'],
                ],
            ], 201)
            ->queueJson([
                'success' => true,
                'query' => ['name' => 'yakhub.com.tr', 'type' => 'A'],
                'resolvers' => [
                    'google' => ['provider' => 'Google Public DNS', 'resolved' => true, 'answers' => ['185.199.108.153']],
                ],
            ]);

        $dns = new CloudDns(new Client(['apiKey' => 'yk_live_dns'], $mock));

        $zone = $dns->zones->insert(new CreateZoneRequest([
            'name' => 'yakhub-zone',
            'dns_name' => 'yakhub.com.tr',
        ]));
        $this->assertSame('yakhub.com.tr.', $zone->getZone()?->getDnsName());

        $rec = $dns->records->insert('1', new CreateRecordRequest([
            'name' => 'api',
            'type' => 'A',
            'rrdatas' => ['185.199.108.153'],
        ]));
        $this->assertSame('A', $rec->getRecord()?->getType());

        $prop = $dns->propagation->check(new CheckPropagationRequest([
            'name' => 'yakhub.com.tr',
            'type' => 'A',
        ]));
        $this->assertTrue($prop->getSuccess());
    }

    public function testNewServicesYakopediaCloudArmorAndAuthenticator(): void
    {
        $mock = (new MockTransport())
            ->queueJson([
                'success' => true,
                'provider' => 'Yakopedia',
                'query' => 'Galaksi',
                'count' => 1,
                'results' => [
                    [
                        'title' => 'Galaksi',
                        'pageid' => 12345,
                        'snippet' => 'Yıldız ve gaz kümesi',
                        'wordcount' => 450,
                        'yakopedia_url' => 'https://yakopedia.yakhub.com.tr/wiki/Galaksi',
                    ],
                ],
            ])
            ->queueJson([
                'success' => true,
                'challenge' => [
                    'algorithm' => 'SHA-256',
                    'challenge' => 'abc123pow',
                    'maxnumber' => 25000,
                    'salt' => 'random_salt',
                    'signature' => 'sig_xyz',
                    'expires_at' => '2026-11-14T12:00:00Z',
                ],
            ])
            ->queueJson([
                'success' => true,
                'prompt' => [
                    'id' => 'prompt-uuid-999',
                    'status' => 'pending',
                    'matching_number' => 42,
                    'challenge_numbers' => [42, 19, 87],
                    'expires_at' => '2026-11-14T12:03:00Z',
                    'created_at' => '2026-11-14T12:00:00Z',
                ],
            ]);

        $client = new Client(['api_key' => 'yk_live_test_key'], $mock);

        // Yakopedia
        $yakopedia = new \YakNet\Api\Yakopedia($client);
        $search = $yakopedia->articles->search(['query' => 'Galaksi']);
        $this->assertTrue($search->getSuccess());
        $this->assertSame('Galaksi', $search->getResults()[0]?->getTitle());

        // Cloud Armor
        $armor = new \YakNet\Api\CloudArmor($client);
        $chal = $armor->challenges->create(new \YakNet\Api\CloudArmor\CreateChallengeRequest(['max_number' => 25000]));
        $this->assertTrue($chal->getSuccess());
        $this->assertSame('SHA-256', $chal->getChallenge()?->getAlgorithm());

        // Authenticator
        $auth = new \YakNet\Api\Authenticator($client);
        $prompt = $auth->prompts->dispatch(new \YakNet\Api\Authenticator\DispatchPromptRequest([
            'user_identifier' => 'enes@yakhub.com.tr',
            'device_name' => 'YakNet Test Client',
        ]));
        $this->assertTrue($prompt->getSuccess());
        $this->assertSame('prompt-uuid-999', $prompt->getPrompt()?->getId());
        $this->assertSame(42, $prompt->getPrompt()?->getMatchingNumber());
    }

    public function testYakTubeVideoPlatformService(): void
    {
        $mock = (new MockTransport())
            ->queueJson([
                'total' => 1,
                'data' => [
                    [
                        'id' => 101,
                        'uuid' => 'uuid-101',
                        'name' => 'YakNet Tanıtım',
                        'description' => 'YakTube video platformu',
                    ],
                ],
            ])
            ->queueJson([
                'id' => 101,
                'uuid' => 'uuid-101',
                'name' => 'YakNet Tanıtım',
                'description' => 'YakTube video platformu',
            ])
            ->queueJson([
                'categories' => ['1' => 'Müzik', '2' => 'Eğitim'],
            ])
            ->queueJson([
                'instance' => ['name' => 'YakTube'],
                'serverVersion' => '6.0.0',
            ])
            ->queueJson([
                'success' => true,
                'saved' => true,
                'query' => 'Erişilebilirlik',
                'history' => ['Erişilebilirlik'],
                'synced_at' => '2026-11-14T12:00:00Z',
            ]);

        $client = new Client(['api_key' => 'yk_live_test_key'], $mock);
        $yaktube = new \YakNet\Api\YakTube($client);

        // 1. Videos
        $videos = $yaktube->videos->listResource(['count' => 10]);
        $this->assertSame(1, $videos->getTotal());
        $this->assertSame('YakNet Tanıtım', $videos->getData()[0]?->getName());

        $video = $yaktube->videos->get('101');
        $this->assertSame(101, $video->getId());
        $this->assertSame('uuid-101', $video->getUuid());
        $this->assertSame('https://yaktube.yakhub.com.tr/videos/embed/uuid-101?autoplay=1&muted=1', $video->getEmbedUrl(['autoplay' => 1, 'muted' => 1]));
        $this->assertStringContainsString('<iframe title="YakNet Tanıtım" width="640" height="360"', $video->getEmbedHtml(640, 360));

        // 2. Categories
        $cats = $yaktube->categories->listResource();
        $this->assertIsArray($cats->getCategories());

        // 3. Instance
        $config = $yaktube->instance->config();
        $this->assertSame('6.0.0', $config->getServerVersion());

        // 4. Search History
        $histRes = $yaktube->searchHistory->add(new \YakNet\Api\YakTube\SearchHistoryAddRequest([
            'query' => 'Erişilebilirlik',
            'device_name' => 'SDK Test',
        ]));
        $this->assertTrue($histRes->getSuccess());
        $this->assertTrue($histRes->getSaved());
        $this->assertSame('Erişilebilirlik', $histRes->getQuery());
    }

    public function testCloudFinanceService(): void
    {
        $mock = (new MockTransport())
            ->queueJson([
                'success' => true,
                'base' => 'TRY',
                'date' => '2026-11-14',
                'rates' => [
                    'USD' => ['buying' => 35.45, 'selling' => 35.52, 'name' => 'ABD Doları'],
                    'EUR' => ['buying' => 38.60, 'selling' => 38.68, 'name' => 'Euro'],
                    'TRY' => ['buying' => 1.0, 'selling' => 1.0, 'name' => 'Türk Lirası'],
                ],
            ])
            ->queueJson([
                'success' => true,
                'from' => 'USD',
                'to' => 'TRY',
                'amount' => 100.0,
                'rate' => 35.52,
                'result' => 3552.0,
            ])
            ->queueJson([
                'success' => true,
                'valid' => true,
                'iban' => 'TR880006200511122233344455',
                'formatted' => 'TR88 0006 2005 1112 2233 3444 55',
                'country_code' => 'TR',
                'bank_code' => '00062',
                'bank_name' => 'Türkiye Garanti Bankası A.Ş.',
            ])
            ->queueJson([
                'success' => true,
                'type' => 'exclusive',
                'tax_rate' => 20.0,
                'base_amount' => 1000.0,
                'tax_amount' => 200.0,
                'total_amount' => 1200.0,
            ])
            ->queueJson([
                'success' => true,
                'valid' => true,
                'vkn' => '1234567890',
                'type' => 'corporate_tax_id',
            ]);

        $client = new Client(['api_key' => 'yk_live_test_key'], $mock);
        $finance = new \YakNet\Api\CloudFinance($client);

        // 1. Rates Latest
        $rates = $finance->rates->latest();
        $this->assertTrue($rates->getSuccess());
        $this->assertSame('TRY', $rates->getBase());

        // 2. Convert
        $convert = $finance->rates->convert(['from' => 'USD', 'to' => 'TRY', 'amount' => 100]);
        $this->assertTrue($convert->getSuccess());
        $this->assertSame(3552.0, $convert->getResult());

        // 3. IBAN Validate
        $iban = $finance->iban->validate(new \YakNet\Api\CloudFinance\IbanValidateRequest([
            'iban' => 'TR880006200511122233344455',
        ]));
        $this->assertTrue($iban->getSuccess());
        $this->assertTrue($iban->getValid());
        $this->assertSame('00062', $iban->getBankCode());

        // 4. Tax Calculate
        $tax = $finance->tax->calculate(new \YakNet\Api\CloudFinance\TaxCalculateRequest([
            'amount' => 1000.0,
            'rate' => 20.0,
        ]));
        $this->assertTrue($tax->getSuccess());
        $this->assertSame(200.0, $tax->getTaxAmount());
        $this->assertSame(1200.0, $tax->getTotalAmount());

        // 5. VKN Validate
        $vkn = $finance->vkn->validate(new \YakNet\Api\CloudFinance\VknValidateRequest([
            'vkn' => '1234567890',
        ]));
        $this->assertTrue($vkn->getSuccess());
        $this->assertTrue($vkn->getValid());
    }

    public function testDiscoveryGeneratorRegeneratesCleanlyFromLocalSpecs(): void
    {
        $discoveryDir = dirname(__DIR__).DIRECTORY_SEPARATOR.'discovery';
        $tempOut = sys_get_temp_dir().DIRECTORY_SEPARATOR.'yaknet_sdk_gen_test_'.uniqid();

        $generator = new DiscoveryGenerator();
        $stats = $generator->generateFromDirectory($discoveryDir, $tempOut);

        $this->assertSame(26, $stats['services']);
        $this->assertGreaterThan(285, $stats['files']);
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'Storage.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'CloudDns.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'CloudKms.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'Dlp.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'Totp.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'Yakopedia.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'CloudArmor.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'Authenticator.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'YakTube.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'CloudFinance.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'CloudFinance'.DIRECTORY_SEPARATOR.'Resource'.DIRECTORY_SEPARATOR.'Rates.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'YakTube'.DIRECTORY_SEPARATOR.'Resource'.DIRECTORY_SEPARATOR.'Videos.php');
        $this->assertFileExists($tempOut.DIRECTORY_SEPARATOR.'Storage'.DIRECTORY_SEPARATOR.'Resource'.DIRECTORY_SEPARATOR.'Objects.php');

        // Cleanup temporary directory
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempOut, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($tempOut);
    }
}
