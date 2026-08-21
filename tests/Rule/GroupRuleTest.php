<?php

declare(strict_types=1);

namespace App\Tests\Rule;

use App\Rule\GroupRule;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
final class GroupRuleTest extends TestCase
{
    public function testFullAddressDerivesDomain(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@Company.com',
            'pattern' => '^(info)@',
        ]);

        self::assertSame('all@company.com', $rule->email());
        self::assertSame('company.com', $rule->domain());
    }

    public function testDomainLessAddressComposesWithDomain(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all',
            'domain' => 'Company.com',
            'pattern' => '^(info)@',
        ]);

        self::assertSame('all@company.com', $rule->email());
        self::assertSame('company.com', $rule->domain());
    }

    public function testDomainLessAddressWithoutDomainThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no domain');

        GroupRule::fromConfigEntry([
            'address' => 'all',
            'pattern' => '^(info)@',
        ]);
    }

    public function testPatternAndRecipientsCompose(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'pattern' => '^(info)@',
            'recipients' => ['consultant@external.com'],
        ]);

        self::assertTrue($rule->hasPattern());
        self::assertSame(['consultant@external.com'], $rule->recipients());
        // The pattern still filters the domain-derived set...
        self::assertTrue($rule->matches('alice@company.com'));
        self::assertFalse($rule->matches('info@company.com'));
        // ...while manual recipients live outside the domain scope.
        self::assertFalse($rule->matches('consultant@external.com'));
    }

    public function testNeitherPatternNorRecipientsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must set either');

        GroupRule::fromConfigEntry(['address' => 'all@company.com']);
    }

    public function testInvalidPatternThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid rule pattern');

        GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'pattern' => '[',
        ]);
    }

    public function testUndelimitedPatternIsWrapped(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'pattern' => '^(info|noreply)@',
        ]);

        self::assertFalse($rule->matches('info@company.com'));
        self::assertFalse($rule->matches('noreply@company.com'));
        self::assertTrue($rule->matches('alice@company.com'));
    }

    public function testDelimitedPatternWithModifierWorks(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'pattern' => '~^(INFO|Noreply)@~i',
        ]);

        self::assertFalse($rule->matches('info@company.com'));
        self::assertFalse($rule->matches('Noreply@company.com'));
        self::assertTrue($rule->matches('alice@company.com'));
    }

    public function testMatchesOnlyScopedDomain(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'pattern' => '^(info)@',
        ]);

        self::assertTrue($rule->matches('alice@company.com'));
        self::assertFalse($rule->matches('alice@other.com'));
        self::assertFalse($rule->matches('info@company.com'));
        // Case-insensitive matching on both sides.
        self::assertTrue($rule->matches('ALICE@COMPANY.COM'));
    }

    public function testStaticRecipientsAreNormalized(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'recipients' => ['Alice@Company.com', ' bob@company.com ', 'alice@company.com'],
        ]);

        self::assertSame(['alice@company.com', 'bob@company.com'], $rule->recipients());
    }

    public function testPatternRuleHasNoStaticRecipients(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'pattern' => '^(info)@',
        ]);

        self::assertTrue($rule->hasPattern());
        self::assertSame([], $rule->recipients());
    }

    public function testStaticRuleHasNoPattern(): void
    {
        $rule = GroupRule::fromConfigEntry([
            'address' => 'all@company.com',
            'recipients' => ['a@company.com'],
        ]);

        self::assertFalse($rule->hasPattern());
        self::assertSame(['a@company.com'], $rule->recipients());
    }
}
