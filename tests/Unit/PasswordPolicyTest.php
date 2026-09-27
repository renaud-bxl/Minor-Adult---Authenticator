<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PasswordBlocklist;
use App\Services\PasswordHasher;
use App\Services\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private const BLOCKLIST = __DIR__ . '/../../resources/security/common-passwords.txt';

    private PasswordPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PasswordPolicy(12, 1024, new PasswordBlocklist(self::BLOCKLIST), ['VeriAge', 'veriage']);
    }

    public function testAcceptsStrongPassphrases(): void
    {
        self::assertNull($this->policy->validate('correct horse battery staple', ['a@b.be', 'Acme SA']));
        self::assertNull($this->policy->validate('Thé vert, 3 tartines & 1 vélo', ['jean@exemple.be']));
        self::assertNull($this->policy->validate(str_repeat('ab1', 3) . 'Zq9!x-wolf-lamp'));
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        self::assertSame(['site.validation.password_too_short', ['min' => 12]], $this->policy->validate('short'));
        // 11 caractères accentués (22 octets) : trop court ; 12 : longueur suffisante.
        self::assertSame('site.validation.password_too_short', $this->policy->validate('éàèùâêîôûçë')[0] ?? null);
        self::assertNotSame('site.validation.password_too_short', $this->policy->validate('éàèùâêîôûçëï')[0] ?? null);
        self::assertSame(['site.validation.password_too_long', ['max' => 1024]], $this->policy->validate(str_repeat('word ', 205)));
    }

    public function testRejectsInvalidUtf8(): void
    {
        self::assertSame(['site.validation.text_invalid', []], $this->policy->validate("valid prefix \xFF\xFE end"));
    }

    /** @return iterable<string, array{string}> */
    public static function trivialProvider(): iterable
    {
        yield 'répétition' => ['aaaaaaaaaaaa'];
        yield 'peu de caractères distincts' => ['abababababab'];
        yield 'motif répété' => ['xk9!xk9!xk9!'];
        yield 'suite de chiffres avec retour' => ['123456789012'];
        yield 'suite inversée' => ['987654321098'];
        yield 'alphabet' => ['bcdefghijklm'];
        yield 'clavier AZERTY' => ['azertyuiopqsdf'];
        yield 'clavier QWERTY inversé' => ['lkjhgfdsapoiuy'];
    }

    #[DataProvider('trivialProvider')]
    public function testRejectsTrivialPasswords(string $password): void
    {
        self::assertSame(['site.validation.password_trivial', []], $this->policy->validate($password));
    }

    /** @return iterable<string, array{string}> */
    public static function commonProvider(): iterable
    {
        yield 'liste, tel quel' => ['password1234'];
        yield 'liste, casse différente' => ['PassWord1234'];
        yield 'mot courant décoré' => ['!!Sunshine2026'];
        yield 'mot courant + chiffres' => ['iloveyou2026'];
        yield 'clavier + chiffres' => ['qwertyuiop12'];
    }

    #[DataProvider('commonProvider')]
    public function testRejectsCommonPasswords(string $password): void
    {
        self::assertSame(['site.validation.password_common', []], $this->policy->validate($password));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function contextualProvider(): iterable
    {
        yield 'e-mail complet' => ['Jean.Dupont@acme.be', ['jean.dupont@acme.be']];
        yield 'partie locale' => ['jeandupont-in-2026!', ['jean.dupont@acme.be']];
        yield 'domaine de l\'e-mail' => ['brasseriedelta&co42', ['ceo@brasseriedelta.be']];
        yield 'raison sociale' => ['Lambic lovers forever', ['x@y.be', 'Brasserie Lambic SRL']];
        yield 'raison sociale compacte' => ['mybrasserie-lambic', ['x@y.be', 'Brasserie Lambic']];
        yield 'nom du service' => ['my VeriAge account 7', []];
    }

    /** @param list<string> $context */
    #[DataProvider('contextualProvider')]
    public function testRejectsContextualPasswords(string $password, array $context): void
    {
        self::assertSame(['site.validation.password_contextual', []], $this->policy->validate($password, $context));
    }

    public function testShortContextWordsAreIgnored(): void
    {
        // « ab » (partie locale) et « SA » sont trop courts pour être significatifs.
        self::assertNull($this->policy->validate('quiet river stones at dawn', ['ab@cd.be', 'SA']));
    }

    public function testHasherUsesArgon2idAndNormalizesUnicode(): void
    {
        $hasher = new PasswordHasher(['memory_cost' => 8192, 'time_cost' => 1, 'threads' => 1]);
        $hash = $hasher->hash("caf\u{00E9} au lait 2026");
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify("caf\u{00E9} au lait 2026", $hash));
        self::assertTrue($hasher->verify("cafe\u{0301} au lait 2026", $hash), 'NFD accepté (NFC avant hachage)');
        self::assertFalse($hasher->verify('cafe au lait 2026', $hash));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue((new PasswordHasher(['memory_cost' => 16384, 'time_cost' => 1, 'threads' => 1]))->needsRehash($hash));
    }

    public function testBlocklistLookupIsExact(): void
    {
        $blocklist = new PasswordBlocklist(self::BLOCKLIST);
        $lines = file(self::BLOCKLIST, FILE_IGNORE_NEW_LINES) ?: [];
        self::assertGreaterThan(100000, count($lines));

        $sorted = $lines;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $lines, 'fichier trié par octets (condition de la dichotomie)');

        // Première, dernière et une entrée sur 997 : toutes trouvées.
        foreach ([0, count($lines) - 1, ...range(1, count($lines) - 2, 997)] as $index) {
            self::assertTrue($blocklist->contains($lines[$index]), $lines[$index]);
        }
        foreach (['', 'correct horse battery staple', "password\npassword", 'zzzzzzzzzzzzzzzzzzzzz~', ' '] as $absent) {
            self::assertFalse($blocklist->contains($absent), var_export($absent, true));
        }
    }
}
