<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Verification\ApiContext;
use App\Verification\VerificationService;

/**
 * GET /api/v1/verifications?email= : statut (not_verified, pending, failed, verified).
 * DELETE /api/v1/verifications?email= : effacement (droit à l'effacement, RGPD art. 17).
 */
final class VerificationController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var ApiContext $ctx */
        $ctx = $request->attribute('api');

        return Response::json(VerificationService::fromApplication($this->app)->status($ctx, $request->query('email')));
    }

    public function destroy(Request $request): Response
    {
        /** @var ApiContext $ctx */
        $ctx = $request->attribute('api');

        return Response::json(VerificationService::fromApplication($this->app)->erase($ctx, $request->query('email'), $request->ip()));
    }
}
