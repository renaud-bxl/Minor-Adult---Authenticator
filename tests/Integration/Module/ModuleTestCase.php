<?php

declare(strict_types=1);

namespace Tests\Integration\Module;

use App\Core\Response;
use App\Models\Project;
use App\Services\ProjectAdmin;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\HttpClient;
use Tests\Support\TestApplication;

/**
 * Base des tests du module : projets de test (clés, secrets), appels d'API authentifiés et parcours
 * de la page hébergée, sur l'hôte « verify. » comme en production.
 */
abstract class ModuleTestCase extends IntegrationTestCase
{
    protected const ORIGIN = 'https://shop.example';

    /**
     * @param list<string>                         $origins
     * @param array{test?: string, live?: string}   $webhooks
     * @return array{project: Project, keys: array{test: string, live: string}, secrets: array{test: string, live: string}}
     */
    protected function createProject(array $origins = [self::ORIGIN], array $webhooks = [], bool $acceptShared = false, string $name = 'Shop'): array
    {
        $admin = ProjectAdmin::fromApplication($this->app);

        return $admin->create($admin->createAccount($name . ' SA'), $name, $origins, 18, 365, $webhooks, $acceptShared);
    }

    /** @param array<string, mixed>|string|null $body */
    protected function api(string $key, string $method, string $uri, array|string|null $body = null, string $ip = '203.0.113.10'): Response
    {
        return HttpClient::verify($ip)->json($method, $uri, $body, ['Authorization' => 'Bearer ' . $key]);
    }

    /** @return array<string, mixed> */
    protected static function body(Response $response): array
    {
        return json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> session créée (201) */
    protected function newSession(string $key, string $email = 'user@example.be', array $extra = []): array
    {
        $response = $this->api($key, 'POST', '/api/v1/sessions', ['email' => $email, ...$extra]);
        self::assertContains($response->status(), [200, 201], $response->body());

        return self::body($response);
    }

    /** Jeton signé (_state) de la page hébergée. */
    protected static function state(Response $page): string
    {
        if (preg_match('/name="_state" value="([^"]+)"/', $page->body(), $m) !== 1) {
            self::fail('Jeton _state introuvable.');
        }

        return $m[1];
    }

    /** Code à 6 chiffres : affiché sur la page (sandbox) ou lu dans l'e-mail (production). */
    protected static function code(Response $page): string
    {
        if (preg_match('/data-sandbox-code>(\d{6})</', $page->body(), $m) === 1) {
            return $m[1];
        }
        foreach (array_reverse(TestApplication::outbox()) as $mail) {
            if (preg_match('/\b(\d{6})\b/', (string) strstr($mail, 'Content-Type: text/plain'), $m) === 1) {
                return $m[1];
            }
        }
        self::fail('Code introuvable.');
    }

    /**
     * Parcours complet de la page hébergée jusqu'au résultat.
     *
     * @return Response page de résultat
     */
    protected function completeFlow(string $sessionId, string $outcome = 'adult', bool $share = false, string $query = ''): Response
    {
        $client = HttpClient::verify();
        $base = '/s/' . $sessionId;
        $page = $client->get($base . $query);
        self::assertSame(200, $page->status());
        self::assertSame(303, $client->post($base . '/consent' . $query, ['_state' => self::state($page), 'consent' => '1'])->status());
        $page = $client->get($base . $query);
        self::assertSame(303, $client->post($base . '/code' . $query, ['_state' => self::state($page), 'code' => self::code($page)])->status());
        $page = $client->get($base . $query . ($query === '' ? '?' : '&') . 'shared=declined');
        $body = ['_state' => self::state($page), 'outcome' => $outcome];
        if ($share) {
            $body['share'] = '1';
        }
        self::assertSame(303, $client->post($base . '/method/mock' . $query, $body)->status());

        return $client->get($base . $query);
    }
}
