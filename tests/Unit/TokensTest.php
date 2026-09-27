<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Project;
use App\Models\VerificationSession;
use App\Verification\PageToken;
use App\Verification\ReturnToken;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/** Jeton de retour (JWT HS256 court) et jeton signé des formulaires de la page hébergée. */
final class TokensTest extends TestCase
{
    private const SECRET = 'whsec_0123456789abcdefghijABCDEFGHIJ012345';

    public function testReturnTokenRoundTripAndBindings(): void
    {
        $project = $this->project();
        $session = $this->session();
        $token = (new ReturnToken('https://verify.veriage.test', 300))->issue($project, $session, ['status' => 'verified', 'is_adult' => true], self::SECRET, time());
        $claims = ReturnToken::verify($token, self::SECRET, 'prj_test', $session->publicId);
        self::assertNotNull($claims);
        self::assertSame('https://verify.veriage.test', $claims['iss']);
        self::assertSame(300, $claims['exp'] - $claims['iat']);
        self::assertTrue($claims['is_adult']);
        self::assertSame('HS256', json_decode(base64_decode(explode('.', $token)[0]), true)['alg']);

        self::assertNull(ReturnToken::verify($token, self::SECRET . 'x', 'prj_test', $session->publicId), 'autre secret');
        self::assertNull(ReturnToken::verify($token, self::SECRET, 'prj_other', $session->publicId), 'autre projet');
        self::assertNull(ReturnToken::verify($token, self::SECRET, 'prj_test', 'vs_other'), 'autre session');
        self::assertNull(ReturnToken::verify('not.a.jwt', self::SECRET, 'prj_test', $session->publicId));
        $expired = (new ReturnToken('https://verify.veriage.test', 300))->issue($project, $session, [], self::SECRET, time() - 1000);
        self::assertNull(ReturnToken::verify($expired, self::SECRET, 'prj_test', $session->publicId), 'expiré');
        // Algorithme « none » ou changé : refusé.
        $parts = explode('.', $token);
        $none = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=') . '.' . $parts[1] . '.';
        self::assertNull(ReturnToken::verify($none, self::SECRET, 'prj_test', $session->publicId));
        $hs512 = JWT::encode((array) json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true), str_repeat(self::SECRET, 2), 'HS512');
        self::assertNull(ReturnToken::verify($hs512, self::SECRET, 'prj_test', $session->publicId));
    }

    public function testPageTokenIsBoundToSessionAndExpires(): void
    {
        $tokens = new PageToken(str_repeat('k', 32), 3600);
        $now = 1790000000;
        $token = $tokens->issue('vs_a', $now);
        self::assertTrue($tokens->validate($token, 'vs_a', $now + 3599));
        self::assertFalse($tokens->validate($token, 'vs_b', $now), 'autre session');
        self::assertFalse($tokens->validate($token, 'vs_a', $now + 3601), 'expiré');
        self::assertFalse((new PageToken(str_repeat('z', 32)))->validate($token, 'vs_a', $now), 'autre clé');
        [$expires, $mac] = explode('.', $token);
        self::assertFalse($tokens->validate(($expires + 100) . '.' . $mac, 'vs_a', $now), 'expiration falsifiée');
        foreach (['', 'abc', $expires . '.', $expires . '.' . $mac . 'x'] as $bad) {
            self::assertFalse($tokens->validate($bad, 'vs_a', $now), $bad);
        }
        $this->expectException(\InvalidArgumentException::class);
        new PageToken('short');
    }

    private function project(): Project
    {
        return new Project(1, 'prj_test', 1, 'Shop', 18, 365, ['https://shop.example'], [], false, str_repeat("\0", 32), '', '');
    }

    private function session(): VerificationSession
    {
        return VerificationSession::fromRow([
            'id' => 1, 'public_id' => 'vs_' . str_repeat('a', 32), 'project_id' => 1, 'api_key_id' => null, 'livemode' => 0,
            'email_hash' => str_repeat('0', 64), 'email_enc' => '', 'min_age' => 18, 'return_url' => null, 'lang' => null,
            'external_ref' => 'ref_1', 'status' => 'completed', 'consent_at' => null, 'code_hash' => null, 'code_expires_at' => null,
            'code_attempts' => 0, 'code_sends' => 0, 'code_sent_at' => null, 'email_verified_at' => null, 'share_opt_in' => 0,
            'method' => 'mock', 'reuse' => 'none', 'result_is_adult' => 1, 'result_verified_at' => '2026-09-27 10:00:00',
            'result_expires_at' => '2027-09-27 10:00:00', 'failure_reason' => null, 'expires_at' => '2026-09-27 10:30:00',
            'completed_at' => '2026-09-27 10:00:00', 'created_at' => '2026-09-27 10:00:00',
        ]);
    }
}
