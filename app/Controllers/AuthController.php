<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\IpAddress;
use App\Core\Request;
use App\Core\Response;
use App\Core\TextInput;
use App\Models\AuditLog;
use App\Services\AuthService;
use App\Services\LoginResult;

/** Inscription, validation de l'adresse, connexion et déconnexion des comptes clients. */
final class AuthController extends Controller
{
    private const COMPANY_MAX_LENGTH = 190;

    public function showRegister(Request $request): Response
    {
        return $this->registerForm();
    }

    public function register(Request $request): Response
    {
        $limit = $this->throttleIp('register_ip', $request);
        $rawCompany = $request->input('company');
        $rawEmail = $request->input('email');
        $old = ['company' => TextInput::forDisplay(trim($rawCompany)), 'email' => TextInput::forDisplay(trim($rawEmail))];
        if (!$limit->allowed) {
            return $this->throttled($this->registerForm($old, ['form' => $this->throttledMessage($limit)], 429), $limit);
        }

        $password = $request->input('password');
        $errors = [];
        $company = TextInput::normalize($rawCompany);
        if ($company === null) {
            $errors['company'] = __('site.validation.text_invalid');
        } elseif (mb_strlen($company, 'UTF-8') < 2) {
            $errors['company'] = __('site.validation.company_required');
        } elseif (mb_strlen($company, 'UTF-8') > self::COMPANY_MAX_LENGTH) {
            $errors['company'] = __('site.validation.company_too_long', ['max' => self::COMPANY_MAX_LENGTH]);
        } else {
            $old['company'] = $company;
        }
        $email = self::validEmail($rawEmail);
        if ($email === null) {
            $errors['email'] = __('site.validation.email_invalid');
        }
        $policyError = $this->passwordPolicy()->validate($password, array_values(array_filter([$email ?? $old['email'], $company ?? ''])));
        if ($policyError !== null) {
            $errors['password'] = __($policyError[0], $policyError[1]);
        } elseif (!hash_equals($password, $request->input('password_confirmation'))) {
            $errors['password_confirmation'] = __('site.validation.password_mismatch');
        }
        if ($errors !== [] || $company === null || $email === null) {
            return $this->registerForm($old, $errors, 422);
        }

        // Inscription traitée après l'envoi de la réponse : durée identique que l'adresse soit nouvelle
        // ou déjà inscrite. Au-delà du quota par adresse, rien n'est fait (anti-bombardement d'e-mails),
        // sans réponse différente.
        if ($this->throttle('register_email', Crypto::normalizeEmail($email))->allowed) {
            $auth = AuthService::fromApplication($this->app);
            [$locale, $ip] = [locale(), $request->ip()];
            $this->app->defer(static fn () => $auth->register($company, $email, $password, $locale, $ip));
        }
        $request->session()->flash('success', 'site.register.check_email');

        return $this->redirectTo('/login');
    }

    public function showLogin(Request $request): Response
    {
        return $this->loginForm();
    }

    public function login(Request $request): Response
    {
        // Une saisie invalide (encodage, caractères de contrôle) est traitée comme vide : échec générique.
        $email = TextInput::normalize($request->input('email')) ?? '';
        $password = $request->input('password');

        // Trois compteurs (voir config/security.php), contrôlés dans l'ordre : une tentative refusée par
        // un compteur n'est pas décomptée des suivants, si bien qu'une seule IP ne peut pas épuiser le
        // quota global d'une adresse (verrouillage du compte par un tiers).
        $normalized = Crypto::normalizeEmail($email);
        $ipKey = IpAddress::rateLimitKey($request->ip());
        foreach (['login_ip' => $ipKey, 'login_email_ip' => $normalized . "\0" . $ipKey, 'login_email' => $normalized] as $bucket => $identifier) {
            $limit = $this->throttle($bucket, $identifier);
            if (!$limit->allowed) {
                return $this->throttled($this->loginForm($email, $this->throttledMessage($limit), 429), $limit);
            }
        }

        if ($email === '' || $password === '') {
            return $this->loginForm($email, __('site.login.failed'), 422);
        }

        $auth = AuthService::fromApplication($this->app);
        $result = $auth->attemptLogin($email, $password, $request->ip());

        if ($result->status === LoginResult::UNVERIFIED && $result->user !== null) {
            if ($this->throttle('verification_resend_user', (string) $result->user['id'])->allowed) {
                [$user, $locale] = [$result->user, locale()];
                $this->app->defer(static fn () => $auth->resendVerification($user, $locale));
            }

            return $this->loginForm($email, __('site.login.unverified'), 403);
        }
        if ($result->status !== LoginResult::SUCCESS || $result->user === null || $result->account === null) {
            return $this->loginForm($email, __('site.login.failed'), 422);
        }

        // Seul le compteur de ce couple adresse + IP est remis à zéro : le plafond global reste en place.
        $this->app->rateLimiter()->clear('login_email_ip', $normalized . "\0" . $ipKey);
        $session = $request->session();
        // Changement de privilège : nouvel identifiant de session (anti-fixation) et nouveau jeton CSRF.
        $session->regenerate();
        (new Csrf($session))->rotate();
        $session->set('user_id', (int) $result->user['id']);
        $session->set('auth_version', (int) $result->user['auth_version']);
        if (!$session->has('locale')) {
            $session->set('locale', (string) $result->account['locale']);
        }
        $locale = (string) $session->get('locale');

        return Response::redirect(url('/dashboard', $this->app->translator()->isEnabled($locale) ? $locale : null));
    }

    public function logout(Request $request): Response
    {
        $session = $request->session();
        $userId = $session->get('user_id');
        (new AuditLog($this->app->db()))->record('auth.logout', is_int($userId) ? $userId : null, null, $request->ip());
        $session->invalidate();
        $session->flash('success', 'site.logout.done');

        return $this->redirectTo('/');
    }

    public function verifyEmail(Request $request): Response
    {
        if (!AuthService::fromApplication($this->app)->verifyEmail($request->query('token'), $request->ip())) {
            return $this->view('auth/message', [
                'pageTitle' => __('site.verify.invalid_title'),
                'title' => __('site.verify.invalid_title'),
                'message' => __('site.verify.invalid_message'),
                'linkUrl' => url('/login'),
                'linkLabel' => __('site.verify.back_to_login'),
                'noindex' => true,
            ], 400);
        }

        $session = $request->session();
        $session->flash('success', 'site.verify.success');

        return $this->redirectTo(is_int($session->get('user_id')) ? '/dashboard' : '/login');
    }

    /**
     * @param array{company?: string, email?: string} $old
     * @param array<string, string>                   $errors
     */
    private function registerForm(array $old = [], array $errors = [], int $status = 200): Response
    {
        $policy = $this->passwordPolicy();

        return $this->view('auth/register', [
            'pageTitle' => __('site.register.title'),
            'old' => $old,
            'errors' => $errors,
            'passwordMin' => $policy->minLength(),
            'passwordMax' => $policy->maxLength(),
        ], $status);
    }

    private function loginForm(string $email = '', ?string $error = null, int $status = 200): Response
    {
        return $this->view('auth/login', [
            'pageTitle' => __('site.login.title'),
            'email' => $email,
            'error' => $error,
        ], $status);
    }
}
