<?php

declare(strict_types=1);

namespace Pimcore\Bundle\DataHubBundle\Tests\Service\RequestValidation;

use Pimcore\Bundle\DataHubBundle\GraphQL\Exception\ClientSafeException;
use Pimcore\Bundle\DataHubBundle\Service\RequestValidation\RequestVariableValidator;

final class RequestVariableValidatorTest extends TempfileTestCase
{
    private const CLIENT = 'public-content';

    /**
     * @param list<string> $enforcedClients
     */
    private function validator(string $rulesPath, array $enforcedClients = [self::CLIENT]): CapturingRequestVariableValidator
    {
        return new CapturingRequestVariableValidator(new CapturingRulesLoader($rulesPath), $enforcedClients);
    }

    private function contractValidator(array $enforcedClients = [self::CLIENT]): CapturingRequestVariableValidator
    {
        $enum = ['kind' => 'enum', 'values' => ['en', 'de', 'ja', 'zh', 'zh_Hant_TW']];
        $rules = [
            'versions' => [
                '1' => [
                    'operations' => [
                        'getBrandListing' => ['variables' => ['defaultLanguage' => $enum]],
                        'getJobListingListing' => ['variables' => [
                            'defaultLanguage' => $enum,
                            'first' => ['kind' => 'const', 'value' => 1000],
                            'after' => ['kind' => 'null'],
                        ]],
                        'getArticleListing' => ['variables' => [
                            'defaultLanguage' => $enum,
                            'filter' => ['kind' => 'null'],
                            'first' => ['kind' => 'null'],
                            'after' => ['kind' => 'null'],
                            'sortBy' => ['kind' => 'null'],
                            'sortOrder' => ['kind' => 'null'],
                        ]],
                        'getArticle' => ['variables' => [
                            'defaultLanguage' => $enum,
                            'id' => ['kind' => 'int', 'min' => 1, 'nullable' => true],
                            'fullpath' => ['kind' => 'string', 'nullable' => true],
                        ]],
                        'getSiteContentElementListing' => ['variables' => [
                            'defaultLanguage' => $enum,
                            'fullpath' => ['kind' => 'string', 'prefix' => '/Site Content/'],
                        ]],
                        'getAssetListing' => ['variables' => [
                            'defaultLanguage' => $enum,
                            'ids' => ['kind' => 'csv-int'],
                        ]],
                    ],
                ],
            ],
        ];
        $this->writeJson($rules);

        return $this->validator($this->file, $enforcedClients);
    }

    /**
     * defaultLanguage is a required enum on every contract operation; tests that
     * exercise a different variable inherit a valid locale unless they set their
     * own.
     *
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function withLocale(array $variables): array
    {
        return array_key_exists('defaultLanguage', $variables)
            ? $variables
            : ['defaultLanguage' => 'en'] + $variables;
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function assertAccepts(CapturingRequestVariableValidator $v, string $op, array $variables): void
    {
        $v->assertRequest(self::CLIENT, 1, $op, $this->withLocale($variables));
        self::assertSame([], $v->warnings, sprintf('%s should accept', $op));
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function assertRejects(CapturingRequestVariableValidator $v, string $op, array $variables, string $expectedReason): void
    {
        try {
            $v->assertRequest(self::CLIENT, 1, $op, $this->withLocale($variables));
            self::fail(sprintf('%s should reject', $op));
        } catch (ClientSafeException $e) {
            self::assertStringStartsWith(RequestVariableValidator::REJECT_MESSAGE_PREFIX, $e->getMessage());
            self::assertSame('pimcore.datahub', $e->getCategory());
        }
        self::assertNotSame([], $v->warnings);
        $last = $v->warnings[count($v->warnings) - 1];
        self::assertSame(RequestVariableValidator::LOG_SLUG, $last['slug']);
        self::assertSame($expectedReason, $last['context']['reason']);
    }

    public function testLocaleOnlyOpAcceptsValidLocaleRejectsInjection(): void
    {
        $this->assertAccepts($this->contractValidator(), 'getBrandListing', ['defaultLanguage' => 'en']);
        $this->assertRejects(
            $this->contractValidator(),
            'getBrandListing',
            ['defaultLanguage' => '; DROP'],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
    }

    public function testJobListingAcceptsHardCapRejectsOtherFirst(): void
    {
        $this->assertAccepts($this->contractValidator(), 'getJobListingListing', ['first' => 1000, 'after' => null]);
        $this->assertRejects(
            $this->contractValidator(),
            'getJobListingListing',
            ['first' => 5],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
        $this->assertRejects(
            $this->contractValidator(),
            'getJobListingListing',
            ['first' => 999],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
        $this->assertRejects(
            $this->contractValidator(),
            'getJobListingListing',
            ['first' => 1001],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
    }

    public function testResourceLibraryListingAcceptsAllNullRejectsNonNullFilter(): void
    {
        $this->assertAccepts($this->contractValidator(), 'getArticleListing', [
            'filter' => null, 'first' => null, 'after' => null, 'sortBy' => null, 'sortOrder' => null,
        ]);
        $this->assertRejects(
            $this->contractValidator(),
            'getArticleListing',
            ['filter' => '{"x":1}'],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
    }

    public function testSingularItemAcceptsValidIdAndSafeFullpathRejectsBadId(): void
    {
        $this->assertAccepts($this->contractValidator(), 'getArticle', ['id' => 1]);
        $this->assertAccepts($this->contractValidator(), 'getArticle', ['fullpath' => '/Articles/My-Article']);
        $this->assertRejects(
            $this->contractValidator(),
            'getArticle',
            ['id' => 0],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
        $this->assertRejects(
            $this->contractValidator(),
            'getArticle',
            ['id' => -1],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
        $this->assertRejects(
            $this->contractValidator(),
            'getArticle',
            ['fullpath' => "/Articles/'; DROP--"],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
    }

    public function testSiteContentElementRequiresPrefixedFullpath(): void
    {
        $this->assertAccepts($this->contractValidator(), 'getSiteContentElementListing', [
            'fullpath' => '/Site Content/footer',
        ]);
        $this->assertRejects(
            $this->contractValidator(),
            'getSiteContentElementListing',
            ['fullpath' => '/Other/footer'],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
    }

    public function testAssetListingAcceptsCsvIntsRejectsNonInt(): void
    {
        $this->assertAccepts($this->contractValidator(), 'getAssetListing', ['ids' => '1,2,3']);
        $this->assertRejects(
            $this->contractValidator(),
            'getAssetListing',
            ['ids' => '1,x'],
            RequestVariableValidator::REASON_CONSTRAINT_FAILED
        );
    }

    public function testNoRulesFileNeverThrows(): void
    {
        $v = $this->validator('');
        $v->assertRequest(self::CLIENT, 1, 'anyOp', ['anything' => 'goes', 'first' => 9999]);
        self::assertSame([], $v->warnings);
    }

    public function testNonEnforcedClientNeverThrows(): void
    {
        $v = $this->contractValidator(enforcedClients: []);
        $v->assertRequest(self::CLIENT, 1, 'getBrandListing', ['defaultLanguage' => '; DROP']);
        self::assertSame([], $v->warnings);
    }

    public function testClientNotInEnforcedSetNeverThrows(): void
    {
        $v = $this->contractValidator(enforcedClients: ['some-other-client']);
        $v->assertRequest(self::CLIENT, 1, 'getBrandListing', ['defaultLanguage' => '; DROP']);
        self::assertSame([], $v->warnings);
    }

    public function testUnknownOperationUnderEnforcedClientThrows(): void
    {
        $this->assertRejects(
            $this->contractValidator(),
            'getUndeclaredOp',
            [],
            RequestVariableValidator::REASON_OPERATION_NOT_ALLOWED
        );
    }

    public function testNullOperationNameThrowsOperationNotAllowed(): void
    {
        $v = $this->contractValidator();

        try {
            $v->assertRequest(self::CLIENT, 1, null, []);
            self::fail('null operation should reject');
        } catch (ClientSafeException) {
        }
        self::assertSame(RequestVariableValidator::REASON_OPERATION_NOT_ALLOWED, $v->warnings[0]['context']['reason']);
    }

    public function testUndeclaredVariableThrows(): void
    {
        $this->assertRejects(
            $this->contractValidator(),
            'getBrandListing',
            ['defaultLanguage' => 'en', 'rogueVariable' => 'x'],
            RequestVariableValidator::REASON_UNKNOWN_VARIABLE
        );
    }

    public function testDeclaredNullKeyAbsentPasses(): void
    {
        // getArticle.fullpath is declared nullable; absent should pass.
        $this->assertAccepts($this->contractValidator(), 'getArticle', ['id' => 5]);
    }

    public function testDeclaredKindNullAbsentVariablePasses(): void
    {
        $this->assertAccepts($this->contractValidator(), 'getJobListingListing', ['first' => 1000]);
    }

    public function testRejectLogContextTruncatesValueAndCarriesSlug(): void
    {
        $v = $this->contractValidator();
        $long = str_repeat('A', 200);

        try {
            $v->assertRequest(self::CLIENT, 1, 'getArticle', ['defaultLanguage' => 'en', 'fullpath' => $long . ';']);
            self::fail('should reject unsafe long fullpath');
        } catch (ClientSafeException) {
        }
        $ctx = $v->warnings[0]['context'];
        self::assertSame(RequestVariableValidator::LOG_SLUG, $v->warnings[0]['slug']);
        self::assertSame(self::CLIENT, $ctx['client']);
        self::assertSame('getArticle', $ctx['operation']);
        self::assertIsString($ctx['value']);
        self::assertSame(40 + strlen('…'), strlen($ctx['value']), 'logged value truncated to limit + ellipsis');
        self::assertStringEndsWith('…', $ctx['value']);
    }
}
