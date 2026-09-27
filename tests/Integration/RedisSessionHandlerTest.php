<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\RedisSessionHandler;
use App\Core\Session;

final class RedisSessionHandlerTest extends IntegrationTestCase
{
    public function testSessionRoundTripWithIdleTtl(): void
    {
        $handler = new RedisSessionHandler($this->app->redis(), 120);
        $session = new Session($handler, 's', true, 3600);
        $session->start(null);
        $session->set('user_id', 12);
        $session->save();

        $ttl = $this->app->redis()->ttl('sess:' . $session->id());
        self::assertGreaterThan(100, $ttl);
        self::assertLessThanOrEqual(120, $ttl);

        $again = new Session($handler, 's', true, 3600);
        $again->start($session->id());
        self::assertSame(12, $again->get('user_id'));

        $again->invalidate();
        self::assertSame(0, $this->app->redis()->exists('sess:' . $session->id()));
    }

    public function testAConcurrentRequestCannotResurrectADestroyedSession(): void
    {
        $handler = new RedisSessionHandler($this->app->redis(), 120);
        $login = new Session($handler, 's', true, 3600);
        $login->start(null);
        $login->set('user_id', 12);
        $login->save();
        $id = $login->id();

        // Requête lente ouverte avec la session…
        $slow = new Session(new RedisSessionHandler($this->app->redis(), 120), 's', true, 3600);
        $slow->start($id);
        // … pendant qu'une autre requête déconnecte l'utilisateur.
        $logout = new Session(new RedisSessionHandler($this->app->redis(), 120), 's', true, 3600);
        $logout->start($id);
        $logout->invalidate();
        $logout->save();

        self::assertFalse($slow->save(), 'la session détruite n\'est pas réécrite');
        self::assertSame(0, $this->app->redis()->exists('sess:' . $id));
    }
}
