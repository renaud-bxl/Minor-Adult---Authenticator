<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Verification\ApiContext;
use App\Verification\VerificationService;

/**
 * POST /api/v1/sessions : création d'une session de vérification (appel serveur à serveur),
 * idempotente avec l'en-tête « Idempotency-Key » (voir VerificationService::createSession).
 */
final class SessionController extends Controller
{
    public function store(Request $request): Response
    {
        /** @var ApiContext $ctx */
        $ctx = $request->attribute('api');
        [$status, $body, $replayed] = VerificationService::fromApplication($this->app)
            ->createSession($ctx, $request->json(), $request->ip(), $request->header('Idempotency-Key'));
        $response = Response::json($body, $status);
        if ($replayed) {
            $response->setHeader('Idempotent-Replayed', 'true');
        }

        return $response;
    }
}
