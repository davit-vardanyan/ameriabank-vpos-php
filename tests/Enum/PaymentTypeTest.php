<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Enum;

use function count;

use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

#[CoversClass(PaymentType::class)]
final class PaymentTypeTest extends TestCase
{
    /**
     * Path to the API surface manifest, which CONVENTIONS.md §2 ranks tier 1.
     */
    private const string MANIFEST = __DIR__ . '/../../docs/api-reference/api-surface.json';

    /**
     * Every case with the capability the gateway actually grants it.
     *
     * Hand-maintained and deliberately exhaustive: GetBindings,
     * ActivateBinding and DeactivateBinding accept 5 and 6 only, and every
     * other value — PayPal included — answers HTTP 500 with an unparseable
     * body (CONVENTIONS.md §4.6). A provider covering a sample of three passes
     * while the fourth is wrong, and the wrong one costs a caller an error
     * they cannot parse, so all thirteen are listed.
     * testTheCapabilityProviderCoversEveryCase() keeps it complete.
     *
     * @return array<string, array{PaymentType, bool}>
     */
    public static function capabilities(): array
    {
        return [
            'None' => [PaymentType::None, false],
            'Arca' => [PaymentType::Arca, false],
            'MasterCard' => [PaymentType::MasterCard, false],
            'Visa' => [PaymentType::Visa, false],
            'Reward' => [PaymentType::Reward, false],
            'MainRest' => [PaymentType::MainRest, true],
            'BindingMainRest' => [PaymentType::BindingMainRest, true],
            'PayPal' => [PaymentType::PayPal, false],
            'PayX' => [PaymentType::PayX, false],
            'MirCard' => [PaymentType::MirCard, false],
            'ApplePay' => [PaymentType::ApplePay, false],
            'EPGCardApplePay' => [PaymentType::EPGCardApplePay, false],
            'Amex' => [PaymentType::Amex, false],
        ];
    }

    /**
     * Values the bank has left unassigned, plus values outside the range.
     *
     * 8, 9, 10, 15 and 16 are the gaps in PaymentsEnum. The bank will fill
     * them without notice, and CONVENTIONS.md §4.6 requires that arriving at
     * one degrades to null rather than throwing, which is the whole reason
     * from() is banned.
     *
     * @return array<string, array{int}>
     */
    public static function unassignedValues(): array
    {
        return [
            'gap 8' => [8],
            'gap 9' => [9],
            'gap 10' => [10],
            'gap 15' => [15],
            'gap 16' => [16],
            'above the highest member' => [18],
            'far outside the range' => [9999],
            'negative' => [-1],
        ];
    }

    /**
     * Direction one: nothing the bank declares may be missing from the enum.
     *
     * This is the test that converts the manifest from a document someone read
     * once into an enforced contract. The thirteen cases are written out in
     * this package's own notes too, so transcribing them from there would
     * prove only that two copies of the same typo agree. The manifest is
     * reflected from the bank's live C# model; when the one-shot Help-page
     * scraper is next run, drift lands here.
     */
    public function testNoManifestMemberIsMissingFromTheEnum(): void
    {
        $manifest = $this->manifestMembers();
        $enum = $this->enumMembers();

        foreach ($manifest as $name => $value) {
            self::assertArrayHasKey(
                $name,
                $enum,
                sprintf('PaymentsEnum declares %s = %d, but PaymentType has no such case.', $name, $value),
            );
            self::assertSame(
                $value,
                $enum[$name],
                sprintf('PaymentType::%s must carry the value the manifest declares.', $name),
            );
        }
    }

    /**
     * Direction two: the enum may not invent a member the bank does not declare.
     *
     * Asserted separately from direction one because the two fail for opposite
     * reasons and a single comparison reports only that "the arrays differ".
     * An invented case is the more dangerous of the two — it would be dispatched
     * to the gateway as a value the gateway does not know.
     */
    public function testNoEnumCaseIsAbsentFromTheManifest(): void
    {
        $manifest = $this->manifestMembers();

        foreach (PaymentType::cases() as $case) {
            self::assertArrayHasKey(
                $case->name,
                $manifest,
                sprintf('PaymentType::%s is not declared by PaymentsEnum in the manifest.', $case->name),
            );
            self::assertSame(
                $case->value,
                $manifest[$case->name],
                sprintf('PaymentType::%s must carry the value the manifest declares.', $case->name),
            );
        }
    }

    /**
     * The count is asserted in its own right.
     *
     * Both directional tests above iterate one side and look the other up, so a
     * duplicate name — which PHP forbids in an enum but the manifest does not —
     * could in principle leave the two sets the same size by coincidence. More
     * usefully, this reports the arity difference as a number rather than as a
     * missing key, which is the first thing to look at after a scraper run.
     */
    public function testTheEnumAndTheManifestDeclareTheSameNumberOfMembers(): void
    {
        $manifest = $this->manifestMembers();

        self::assertCount(
            count($manifest),
            PaymentType::cases(),
            'PaymentType and PaymentsEnum have drifted apart in size.',
        );
    }

    /**
     * @param bool $expected Whether GetBindings and friends accept this value.
     */
    #[DataProvider('capabilities')]
    public function testIsBindingCapableIsTrueOnlyForTheTwoAcceptedValues(
        PaymentType $type,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            $type->isBindingCapable(),
            sprintf('PaymentType::%s reports the wrong binding capability.', $type->name),
        );
    }

    /**
     * The provider is hand-maintained, so it needs a completeness check.
     *
     * Without one it is an allowlist: a fourteenth case added to PaymentType —
     * which is exactly what the gaps at 8, 9, 10, 15 and 16 predict — would be
     * exempt from the capability assertion entirely and default silently to
     * whatever isBindingCapable() happens to return for it.
     */
    public function testTheCapabilityProviderCoversEveryCase(): void
    {
        $inProvider = array_keys(self::capabilities());
        $onEnum = array_map(static fn(PaymentType $case): string => $case->name, PaymentType::cases());

        sort($inProvider);
        sort($onEnum);

        self::assertSame($onEnum, $inProvider, 'capabilities() must list every PaymentType case, and no other.');
    }

    /**
     * Pinned as a literal ordered list, not derived from the enum.
     *
     * [5, 6] catches all three failure modes at once: a reorder
     * to [6, 5], a truncation to [5], and a substitution such as [5, 7]. Order
     * is load-bearing rather than incidental — the list is rendered verbatim
     * into ValidationException::unsupportedPaymentType()'s "Allowed: 5, 6", a
     * message already pinned whole by the exception suite.
     */
    public function testBindingCapableValuesIsTheOrderedPairFiveAndSix(): void
    {
        self::assertSame([5, 6], PaymentType::bindingCapableValues());
    }

    /**
     * ...and the list must stay in step with isBindingCapable().
     *
     * The literal above pins the values; this pins the relationship, so the two
     * cannot drift apart quietly by both being edited to agree on something
     * wrong. Derived from cases() in declaration order, which is ascending.
     */
    public function testBindingCapableValuesMatchesTheCasesThatReportThemselvesCapable(): void
    {
        $capable = [];

        foreach (PaymentType::cases() as $case) {
            if ($case->isBindingCapable()) {
                $capable[] = $case->value;
            }
        }

        self::assertSame($capable, PaymentType::bindingCapableValues());
    }

    /**
     * A value the SDK does not know must become null, never an exception.
     *
     * from() would throw ValueError here, which is why CONVENTIONS.md §8 bans
     * it outright: a gateway that starts returning 9 must not take a caller's
     * process down while they wait for an SDK release.
     */
    #[DataProvider('unassignedValues')]
    public function testUnassignedValuesDegradeToNull(int $value): void
    {
        self::assertNull(
            PaymentType::tryFrom($value),
            sprintf('PaymentType must not claim to know the value %d.', $value),
        );
    }

    /**
     * Case name to backing value, read from the manifest.
     *
     * Values arrive as JSON strings ("0", "17") because the Help pages render
     * them as text; the digits-only assertion is what makes the cast to int
     * safe, and it fails loudly rather than silently yielding 0 if the scraper
     * ever starts emitting something else.
     *
     * @return array<string, int>
     */
    private function manifestMembers(): array
    {
        self::assertFileExists(self::MANIFEST, 'The API surface manifest has moved; this test cannot be vacuous.');

        $raw = file_get_contents(self::MANIFEST);
        self::assertIsString($raw);

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('models', $decoded);

        $models = $decoded['models'];
        self::assertIsArray($models);
        self::assertArrayHasKey('PaymentsEnum', $models);

        $model = $models['PaymentsEnum'];
        self::assertIsArray($model);
        self::assertArrayHasKey('fields', $model);

        $fields = $model['fields'];
        self::assertIsArray($fields);
        self::assertNotSame([], $fields, 'PaymentsEnum has no fields: the manifest is truncated or the shape changed.');

        $members = [];

        foreach ($fields as $field) {
            self::assertIsArray($field);
            self::assertArrayHasKey('Name', $field);
            self::assertArrayHasKey('Value', $field);

            $name = $field['Name'];
            $value = $field['Value'];

            self::assertIsString($name);
            self::assertIsString($value);
            self::assertMatchesRegularExpression('/^-?\d+$/', $value);

            $members[$name] = (int) $value;
        }

        return $members;
    }

    /**
     * Case name to backing value, read from the enum.
     *
     * @return array<string, int>
     */
    private function enumMembers(): array
    {
        $members = [];

        foreach (PaymentType::cases() as $case) {
            $members[$case->name] = $case->value;
        }

        return $members;
    }
}
