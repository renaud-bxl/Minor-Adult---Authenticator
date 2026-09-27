<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class HomeController extends Controller
{
    /** « / » : redirection vers la langue mémorisée, sinon celle de l'en-tête Accept-Language, sinon EN. */
    public function root(Request $request): Response
    {
        $sessionLang = $request->session()->get('locale');
        $locale = $this->app->negotiator()->negotiate(
            accountLang: is_string($sessionLang) ? $sessionLang : null,
            acceptLanguage: $request->header('Accept-Language'),
        );
        $response = Response::redirect(url('/', $locale));
        $response->setHeader('Vary', 'Accept-Language, Cookie');

        return $response;
    }

    public function index(Request $request): Response
    {
        return $this->view('home', ['pageTitle' => __('site.home.title')]);
    }
}
