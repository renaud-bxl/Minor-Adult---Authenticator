<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/**
 * GET /api/v1/i18n/{code} : textes du domaine « module » (widget et page de vérification) en JSON.
 * Données publiques : CORS ouvert, cache HTTP d'une heure et validation par ETag (304).
 */
final class I18nController extends Controller
{
    private const MAX_AGE = 3600;

    public function show(Request $request): Response
    {
        $code = (string) $request->routeParam('code');
        $translator = $this->app->translator();
        if (!$translator->isEnabled($code)) {
            throw new HttpException(404);
        }

        $response = Response::json(['locale' => $code, 'messages' => $translator->domain('module', $code)]);
        $etag = '"' . hash('sha256', $response->body()) . '"';
        $ifNoneMatch = array_map('trim', explode(',', (string) $request->header('If-None-Match')));
        if (in_array($etag, $ifNoneMatch, true) || in_array('W/' . $etag, $ifNoneMatch, true)) {
            $response = new Response('', 304);
        }

        $response->setHeader('ETag', $etag);
        $response->setHeader('Cache-Control', 'public, max-age=' . self::MAX_AGE);
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Cross-Origin-Resource-Policy', 'cross-origin');

        return $response;
    }
}
