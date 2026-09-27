<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Session;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySessionHandler;

final class SessionTest extends TestCase
{
    private ArraySessionHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ArraySessionHandler();
    }

    private function session(int $absolute = 3600, bool $secure = true): Session
    {
        return new Session($this->handler, $secure ? '__Host-s' : 's', $secure, $absolute);
    }

    public function testAnonymousSessionWithoutDataIsNotPersisted(): void
    {
        $session = $this->session();
        $session->start(null);
        self::assertFalse($session->save());
        self::assertSame([], $this->handler->store);
    }

    public function testDataPersistsAcrossRequests(): void
    {
        $first = $this->session();
        $first->start(null);
        $first->set('user_id', 7);
        self::assertTrue($first->save());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->id());

        $second = $this->session();
        $second->start($first->id());
        self::assertSame($first->id(), $second->id());
        self::assertSame(7, $second->get('user_id'));
    }

    public function testUnknownOrMalformedIdIsNeverAdopted(): void
    {
        foreach ([str_repeat('a', 64), 'attacker-chosen-id', str_repeat('Z', 64)] as $id) {
            $session = $this->session();
            $session->start($id);
            self::assertNotSame($id, $session->id(), 'anti-fixation');
        }
    }

    public function testCorruptedPayloadStartsAFreshSession(): void
    {
        $id = str_repeat('b', 64);
        $this->handler->store[$id] = 'O:8:"stdClass":0:{}';
        $session = $this->session();
        $session->start($id);
        self::assertNotSame($id, $session->id());
        self::assertNull($session->get('anything'));
    }

    public function testFlashIsAvailableOnlyOnNextRequest(): void
    {
        $s1 = $this->session();
        $s1->start(null);
        $s1->flash('success', 'site.logout.done');
        self::assertNull($s1->getFlash('success'));
        $s1->save();

        $s2 = $this->session();
        $s2->start($s1->id());
        self::assertSame('site.logout.done', $s2->getFlash('success'));
        $s2->save();

        $s3 = $this->session();
        $s3->start($s1->id());
        self::assertNull($s3->getFlash('success'));
    }

    public function testRegenerateKeepsDataAndDestroysOldId(): void
    {
        $session = $this->session();
        $session->start(null);
        $session->set('k', 'v');
        $session->save();
        $oldId = $session->id();

        $session->regenerate();
        $session->save();
        self::assertNotSame($oldId, $session->id());
        self::assertArrayNotHasKey($oldId, $this->handler->store);
        self::assertSame('v', $session->get('k'));
    }

    public function testRegenerateRestartsTheAbsoluteLifetime(): void
    {
        $id = str_repeat('d', 64);
        $this->handler->store[$id] = json_encode(['_created' => time() - 3000]);
        $session = $this->session(3600);
        $session->start($id);
        $session->regenerate();
        $session->save();

        $next = $this->session(3600);
        $next->start($session->id());
        self::assertSame($session->id(), $next->id());
        self::assertGreaterThanOrEqual(time() - 1, $next->get('_created'));
    }

    public function testInvalidateClearsData(): void
    {
        $session = $this->session();
        $session->start(null);
        $session->set('user_id', 1);
        $session->save();
        $oldId = $session->id();

        $session->invalidate();
        self::assertNull($session->get('user_id'));
        self::assertNotSame($oldId, $session->id());
        self::assertArrayNotHasKey($oldId, $this->handler->store);
    }

    public function testAbsoluteLifetimeIsEnforced(): void
    {
        $id = str_repeat('c', 64);
        $this->handler->store[$id] = json_encode(['_created' => time() - 7200, 'user_id' => 1]);
        $session = $this->session(3600);
        $session->start($id);
        self::assertNotSame($id, $session->id());
        self::assertNull($session->get('user_id'));
        self::assertArrayNotHasKey($id, $this->handler->store);
    }

    public function testCookieAttributes(): void
    {
        $session = $this->session();
        $session->start(null);
        self::assertSame('__Host-s=' . $session->id() . '; Path=/; HttpOnly; SameSite=Lax; Secure', $session->cookieHeader());

        $dev = $this->session(3600, false);
        $dev->start(null);
        self::assertStringNotContainsString('Secure', $dev->cookieHeader());
    }
}
