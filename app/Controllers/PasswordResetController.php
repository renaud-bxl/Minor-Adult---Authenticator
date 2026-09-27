<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\Request;
use App\Core\Response;
use App\Core\TextInput;
use App\Models\AccountRepository;
use App\Services\AuthService;

/** Mot de passe oublié (demande de lien) et réinitialisation (jeton valable 1 h). */
final class PasswordResetController extends Controller
{
    public function showForgot(Request $request): Response
    {
        return $this->forgotForm();
    }

    public function sendResetLink(Request $request): Response
    {
        $display = TextInput::forDisplay(trim($request->input('email')));
        $byIp = $this->throttleIp('password_reset_ip', $request);
        if (!$byIp->allowed) {
            return $this->throttled($this->forgotForm($display, $this->throttledMessage($byIp), 429), $byIp);
        }
        $email = self::validEmail($request->input('email'));
        if ($email === null) {
            return $this->forgotForm($display, __('site.validation.email_invalid'), 422);
        }

        // Au-delà du quota par adresse, on répond comme d'habitude sans renvoyer d'e-mail :
        // un message différent révélerait que l'adresse a déjà reçu des liens, donc qu'elle existe.
        // Le traitement (recherche du compte, jeton, e-mail) a lieu après l'envoi de la réponse :
        // sa durée ne trahit donc pas l'existence du compte.
        if ($this->throttle('password_reset_email', Crypto::normalizeEmail($email))->allowed) {
            $auth = AuthService::fromApplication($this->app);
            [$locale, $ip] = [locale(), $request->ip()];
            $this->app->defer(static fn () => $auth->requestPasswordReset($email, $locale, $ip));
        }
        $minutes = intdiv((int) $this->app->config->get('security.tokens.password_reset_ttl'), 60);
        $request->session()->flash('info', ['site.forgot.sent', ['minutes' => $minutes]]);

        return $this->redirectTo('/login');
    }

    public function showReset(Request $request): Response
    {
        $token = $request->query('token');
        if (AuthService::fromApplication($this->app)->userForResetToken($token) === null) {
            return $this->invalidLink();
        }

        return $this->resetForm($token);
    }

    public function reset(Request $request): Response
    {
        $token = $request->input('token');
        $auth = AuthService::fromApplication($this->app);
        $user = $auth->userForResetToken($token);
        if ($user === null) {
            return $this->invalidLink();
        }

        $password = $request->input('password');
        $errors = [];
        $account = (new AccountRepository($this->app->db()))->findPrimaryForUser((int) $user['id']);
        $policyError = $this->passwordPolicy()->validate($password, [(string) $user['email'], (string) ($account['name'] ?? '')]);
        if ($policyError !== null) {
            $errors['password'] = __($policyError[0], $policyError[1]);
        } elseif (!hash_equals($password, $request->input('password_confirmation'))) {
            $errors['password_confirmation'] = __('site.validation.password_mismatch');
        }
        if ($errors !== []) {
            return $this->resetForm($token, $errors, 422);
        }

        if (!$auth->resetPassword($token, $password, locale(), $request->ip())) {
            return $this->invalidLink();
        }
        $request->session()->flash('success', 'site.reset.success');

        return $this->redirectTo('/login');
    }

    private function forgotForm(string $email = '', ?string $error = null, int $status = 200): Response
    {
        return $this->view('auth/forgot', [
            'pageTitle' => __('site.forgot.title'),
            'email' => $email,
            'error' => $error,
        ], $status);
    }

    /** @param array<string, string> $errors */
    private function resetForm(string $token, array $errors = [], int $status = 200): Response
    {
        $policy = $this->passwordPolicy();

        return $this->view('auth/reset', [
            'pageTitle' => __('site.reset.title'),
            'token' => $token,
            'errors' => $errors,
            'passwordMin' => $policy->minLength(),
            'passwordMax' => $policy->maxLength(),
            'noindex' => true,
        ], $status);
    }

    private function invalidLink(): Response
    {
        return $this->view('auth/message', [
            'pageTitle' => __('site.reset.invalid_title'),
            'title' => __('site.reset.invalid_title'),
            'message' => __('site.reset.invalid_message'),
            'linkUrl' => url('/forgot-password'),
            'linkLabel' => __('site.reset.request_new_link'),
            'noindex' => true,
        ], 400);
    }
}
