<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Verification\ApiContext;
use App\Verification\VerificationService;

/**
 * GET /api/v1/verifications?email=&min_age= : statut (not_verified, pending, failed, verified).
 * POST /api/v1/verifications/lookup {"email", "min_age"?} : même réponse, adresse dans le corps
 * (jamais dans l'URL ni dans les journaux d'accès ; pas de piège d'encodage du « + »).
 * DELETE /api/v1/verifications?email= : effacement (droit à l'effacement, RGPD art. 17).
 */
final class VerificationController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var ApiContext $ctx */
        $ctx = $request->attribute('api');

        $minAge = $request->query('min_age');
        $minAge = $minAge === '' ? null : (preg_match('/^\d{1,3}$/D', $minAge) === 1 ? (int) $minAge : $minAge);

        return Response::json(VerificationService::fromApplication($this->app)->status($ctx, $request->query('email'), $minAge));
    }

    public function lookup(Request $request): Response
    {
        /** @var ApiContext $ctx */
        $ctx = $request->attribute('api');
        $input = $request->json();
        $unknown = array_diff(array_keys($input), ['email', 'min_age']);
        if ($unknown !== []) {
            throw new ApiException(422, 'validation_failed', array_fill_keys(array_map('strval', $unknown), 'unknown_field'));
        }
        if (array_key_exists('email', $input) && !is_string($input['email'])) {
            throw new ApiException(422, 'validation_failed', ['email' => 'invalid']);
        }

        return Response::json(VerificationService::fromApplication($this->app)->status(
            $ctx,
            is_string($input['email'] ?? null) ? $input['email'] : '',
            $input['min_age'] ?? null,
        ));
    }

    public function destroy(Request $request): Response
    {
        /** @var ApiContext $ctx */
        $ctx = $request->attribute('api');

        return Response::json(VerificationService::fromApplication($this->app)->erase($ctx, $request->query('email'), $request->ip()));
    }
}
