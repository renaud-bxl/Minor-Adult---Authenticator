<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Core\HostMap;
use App\Verification\Biometrics\BiometricsClient;
use App\Verification\Biometrics\BiometricsException;
use App\Verification\Biometrics\CurlBiometricsTransport;
use Tests\Support\HttpClient;
use Tests\Support\TestApplication;

/**
 * Bout en bout PHP → Python : le VRAI microservice (biometrics/, lancé par le test sur un port libre de
 * 127.0.0.1) analyse de vraies images SYNTHÉTIQUES (document fictif « Utopie » en police OCR-B, séquence de
 * selfie simulée correspondant aux défis tirés par le serveur), relayées par la vraie page hébergée,
 * chiffrées comme par le navigateur, via le vrai transport cURL et l'authentification HMAC mutuelle.
 *
 * Sauté (avec la raison) si le venv, les modèles ou les photos de test manquent : voir README.
 */
final class BiometricsServiceTest extends ModuleTestCase
{
    private const SECRET = 'e2e-php-python-secret-0123456789abcdef-0123';
    /** @var resource|null */
    private static $process = null;
    private static int $port = 0;
    private static ?string $skip = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3) . '/biometrics';
        foreach (['.venv/bin/python', 'models/face_detection_yunet_2023mar.onnx', 'models/face_recognition_sface_2021dec.onnx',
            'models/face_landmarker.task', 'models/tessdata/eng.traineddata', 'tests/assets/obama.jpg', 'tests/assets/obama2.jpg', 'tests/assets/biden.jpg'] as $required) {
            if (!is_file($root . '/' . $required)) {
                self::$skip = $required . ' absent (voir README, « Biométrie locale »)';

                return;
            }
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $env = ['BIOMETRICS_SECRET' => self::SECRET, 'BIOMETRICS_PORT' => (string) self::$port, 'BIOMETRICS_LOG_LEVEL' => 'WARNING',
            'PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')];
        self::$process = proc_open([$root . '/.venv/bin/python', '-m', 'veriage_biometrics'], [0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env) ?: null;
        $client = new BiometricsClient(new CurlBiometricsTransport(1, 5), 'http://127.0.0.1:' . self::$port, self::SECRET);
        for ($i = 0; $i < 120; $i++) {
            try {
                $client->health();

                return;
            } catch (BiometricsException) {
                usleep(250_000);
            }
        }
        self::$skip = 'le microservice n\'a pas démarré';
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$skip !== null) {
            self::markTestSkipped(self::$skip);
        }
        parent::setUp();
        TestApplication::$biometrics = null;
    }

    private function client(): HttpClient
    {
        return new HttpClient('203.0.113.10', (string) HostMap::authority((string) TestApplication::boot()->config->get('app.verify_url')), [
            'biometrics.enabled' => true,
            'biometrics.secret' => self::SECRET,
            'biometrics.url' => 'http://127.0.0.1:' . self::$port,
            'biometrics.challenge.neutral_ms' => 10,
            'biometrics.challenge.step_ms' => 10,
        ]);
    }

    /**
     * Parcours complet jusqu'au résultat, avec des images générées pour les défis tirés par le serveur.
     *
     * @param list<string> $imageOptions options de biometrics/scripts/make_test_images.py
     */
    private function verifyWithImages(string $key, string $email, array $imageOptions): string
    {
        $client = $this->client();
        $id = $this->newSession($key, $email)['session_id'];
        $base = '/s/' . $id;
        $page = $client->get($base);
        $client->post($base . '/consent', ['_state' => self::state($page), 'consent' => '1']);
        $page = $client->get($base);
        $client->post($base . '/code', ['_state' => self::state($page), 'code' => self::code($page)]);
        $page = $client->get($base . '/document');
        $client->post($base . '/document/consent', ['_state' => self::state($page), 'consent' => '1']);
        $page = $client->get($base . '/document');
        self::assertSame(1, preg_match('/data-state="([^"]+)"/', $page->body(), $m));
        $state = $m[1];
        $capture = self::body($client->post($base . '/document/start', ['_state' => $state]));

        $dir = sys_get_temp_dir() . '/veriage-bio-' . bin2hex(random_bytes(6));
        $root = dirname(__DIR__, 3) . '/biometrics';
        $command = [$root . '/.venv/bin/python', $root . '/scripts/make_test_images.py', '--out', $dir, '--challenge', implode(',', $capture['challenge']),
            '--step-ms', '1000', '--interval-ms', '70', ...$imageOptions];
        exec(implode(' ', array_map('escapeshellarg', $command)) . ' 2>/dev/null', $output, $code);
        self::assertSame(0, $code, 'génération des images de test');
        try {
            $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true, 8, JSON_THROW_ON_ERROR);
            $passport = !is_file($dir . '/back.jpg');
            $payload = [
                'document_type' => $passport ? 'passport' : 'id_card',
                'front' => base64_encode((string) file_get_contents($dir . '/front.jpg')),
                'back' => $passport ? null : base64_encode((string) file_get_contents($dir . '/back.jpg')),
                'frames' => array_map(static fn (array $f): array => ['t' => $f['t'], 'step' => $f['step'],
                    'image' => base64_encode((string) file_get_contents($dir . '/frames/' . $f['file']))], $manifest['frames']),
            ];
        } finally {
            array_map('unlink', [...(glob($dir . '/frames/*') ?: []), ...(glob($dir . '/*.*') ?: [])]);
            @rmdir($dir . '/frames');
            @rmdir($dir);
        }
        // Le temps réel écoulé côté serveur doit couvrir la séquence annoncée (horodatages du navigateur).
        $span = end($payload['frames'])['t'];
        usleep(max(0, $span * 1000 - 1_500_000));
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($payload, JSON_THROW_ON_ERROR), 'aes-256-gcm', base64_decode($capture['key'], true), OPENSSL_RAW_DATA, $iv, $tag, $capture['aad'], 16);
        $response = $client->request('POST', $base . '/document/submit', [], ['Content-Type' => 'application/octet-stream',
            'X-VeriAge-State' => $state, 'X-VeriAge-Capture' => $capture['capture_id']], $iv . $ciphertext . $tag);
        self::assertSame(200, $response->status(), $response->body());

        return $client->get((string) self::body($response)['redirect'])->body();
    }

    public function testRealServiceVerifiesAnAdultAndAMinor(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $adult = $this->verifyWithImages($key, 'adult@example.be', ['--birth', '2000-03-14']);
        self::assertStringContainsString('data-result-status="verified"', $adult);
        $status = self::body($this->api($key, 'GET', '/api/v1/verifications?email=adult@example.be'));
        self::assertSame(['verified', true, 'id_document_face'], [$status['status'], $status['is_adult'], $status['method']]);

        // 18 ans demain : mineur aujourd'hui (âge exact, calculé à la date de Bruxelles).
        $tomorrow = new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Brussels'));
        $birth = $tomorrow->format('m-d') === '02-29' ? $tomorrow->modify('-18 years +1 day') : $tomorrow->modify('-18 years');
        $this->verifyWithImages($key, 'minor@example.be', ['--birth', $birth->format('Y-m-d')]);
        $status = self::body($this->api($key, 'GET', '/api/v1/verifications?email=minor@example.be'));
        self::assertSame(['verified', false], [$status['status'], $status['is_adult']]);
    }

    public function testRealServiceRejectsAnotherFaceAndAnExpiredPassport(): void
    {
        $p = $this->createProject();
        $key = $p['keys']['test'];
        $mismatch = $this->verifyWithImages($key, 'other@example.be', ['--document-face', 'biden.jpg']);
        self::assertStringContainsString('data-failure-reason="face_mismatch"', $mismatch);
        $expired = $this->verifyWithImages($key, 'expired@example.be', ['--passport', '--expiry', '2024-01-31']);
        self::assertStringContainsString('data-failure-reason="document_expired"', $expired);
        // Attaques du critique (non-régression, E1) : A (selfie au lieu du recto) et B (recto du mineur, verso
        // d'une carte d'adulte) sont refusées par le vrai service.
        $attackA = $this->verifyWithImages($key, 'attack.a@example.be', ['--birth', '2011-06-02', '--front-selfie', '--back-of', 'UT1111111,1975-05-05,2030-01-01']);
        self::assertStringContainsString('data-failure-reason="document_inconsistent"', $attackA);
        $attackB = $this->verifyWithImages($key, 'attack.b@example.be', ['--birth', '2011-06-02', '--back-of', 'UT1111111,1975-05-05,2030-01-01']);
        self::assertStringContainsString('data-failure-reason="document_inconsistent"', $attackB);
        $logs = implode("\n", TestApplication::logLines());
        self::assertStringNotContainsString('SPECIMEN', $logs);
        self::assertStringNotContainsString('UT1234567', $logs);
    }
}
