<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\AccountRepository;

/** Tableau de bord client (page d'accueil de l'espace client ; complété en phase 5). */
final class DashboardController extends Controller
{
    private const ROLE_LABELS = [
        AccountRepository::ROLE_OWNER => 'site.roles.owner',
        AccountRepository::ROLE_DEVELOPER => 'site.roles.developer',
        AccountRepository::ROLE_ACCOUNTANT => 'site.roles.accountant',
    ];

    public function index(Request $request): Response
    {
        /** @var array<string, mixed> $user */
        $user = $request->attribute('user');
        $account = (new AccountRepository($this->app->db()))->findPrimaryForUser((int) $user['id']);
        if ($account === null) {
            throw new \RuntimeException('Utilisateur sans compte rattaché : ' . (int) $user['id']);
        }
        $utc = new \DateTimeZone('UTC');

        return $this->view('dashboard/index', [
            'pageTitle' => __('site.dashboard.title'),
            'noindex' => true,
            'company' => (string) $account['name'],
            'email' => (string) $user['email'],
            'role' => __(self::ROLE_LABELS[(string) $account['role']] ?? 'site.roles.owner'),
            'memberSince' => $this->app->formatter()->date(new \DateTimeImmutable((string) $account['created_at'], $utc), locale()),
        ]);
    }
}
