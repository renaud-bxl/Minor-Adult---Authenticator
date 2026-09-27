<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Verification\ApiContext;
use App\Verification\VerificationService;

/** POST /api/v1/sessions : création d'une session de vérification (appel serveur à serveur). */
final class SessionController extends Controller
{
    public function store(Request $request): Response
    {
        /** @var ApiContext $ctx */
        $ctx = $request->attribute('api');
        [$status, $body] = VerificationService::fromApplication($this->app)->createSession($ctx, $request->json(), $request->ip());

        return Response::json($body, $status);
    }
}
