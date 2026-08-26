<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Exception;

use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_map;

use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\AuthenticationException;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\DeclinedException;
use DavitVardanyan\AmeriabankVpos\Exception\DuplicateOrderException;
use DavitVardanyan\AmeriabankVpos\Exception\GatewayFaultException;
use DavitVardanyan\AmeriabankVpos\Exception\IndeterminateStateException;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Exception\TransportException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;

use function file_get_contents;

use InvalidArgumentException;

use function json_decode;

use const JSON_THROW_ON_ERROR;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function preg_split;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;

use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;

#[CoversClass(ApiException::class)]
#[CoversClass(AuthenticationException::class)]
#[CoversClass(ConfigurationException::class)]
#[CoversClass(DeclinedException::class)]
#[CoversClass(DuplicateOrderException::class)]
#[CoversClass(GatewayFaultException::class)]
#[CoversClass(IndeterminateStateException::class)]
#[CoversClass(SerializationException::class)]
#[CoversClass(TransportException::class)]
#[CoversClass(ValidationException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    /**
     * The specification of record (CONVENTIONS.md §2), read at test time so
     * this guard's sensitive-field tokens are derived rather than typed.
     */
    private const string MANIFEST = __DIR__ . '/../../docs/api-reference/api-surface.json';

    /**
     * Second column is each class's DIRECT parent, not merely an ancestor.
     *
     * @return list<array{class-string<VposExceptionInterface>, class-string}>
     */
    public static function exceptionClasses(): array
    {
        return [
            [ConfigurationException::class, LogicException::class],
            [ValidationException::class, InvalidArgumentException::class],
            [TransportException::class, RuntimeException::class],
            [IndeterminateStateException::class, RuntimeException::class],
            [ApiException::class, RuntimeException::class],
            [AuthenticationException::class, ApiException::class],
            [DeclinedException::class, ApiException::class],
            [DuplicateOrderException::class, ApiException::class],
            [GatewayFaultException::class, RuntimeException::class],
            [SerializationException::class, RuntimeException::class],
        ];
    }

    /**
     * The provider is hand-maintained, so it needs a completeness check.
     *
     * Without one it is an allowlist: every structural guard in this file
     * iterates the provider, so a class absent from it is exempt from the
     * marker check, the final check and the credential guard simultaneously. A
     * tenth class dropped into src/Exception/ — non-final, not implementing
     * the marker, interpolating a card number and a password into its message
     * — passed the whole suite green. It also walks past CONVENTIONS.md §8's
     * ban on a second non-final class in src/. The default for anything added
     * later must therefore be "guarded".
     */
    public function testTheProviderCoversEveryClassInTheExceptionDirectory(): void
    {
        $onDisk = $this->exceptionClassNamesIn(__DIR__ . '/../../src/Exception');

        $inProvider = [];

        foreach (self::exceptionClasses() as [$class]) {
            $separator = strrpos($class, '\\');
            $inProvider[] = $separator === false ? $class : substr($class, $separator + 1);
        }

        sort($inProvider);

        self::assertNotSame([], $onDisk, 'No classes found on disk: the glob path is wrong.');
        self::assertSame(
            $onDisk,
            $inProvider,
            'Every class in src/Exception/ must appear in exceptionClasses(), and vice versa.',
        );
    }

    /**
     * A completeness guard with no test of its own is the same defect one level
     * up — which is precisely what let the allowlist hole survive this long.
     *
     * The fixtures are empty files in a temporary directory, never a throwaway
     * class written into src/Exception/: a crashed or interrupted run must not be
     * able to leave a stray file in the shipped tree. This works because the
     * check compares basenames and never autoloads.
     */
    public function testTheCompletenessCheckDetectsAClassMissingFromTheProvider(): void
    {
        $directory = sys_get_temp_dir() . '/vpos-exception-completeness-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($directory, 0o700));

        try {
            foreach (['KnownException', 'RogueException', 'VposExceptionInterface'] as $basename) {
                file_put_contents($directory . '/' . $basename . '.php', '');
            }

            $found = $this->exceptionClassNamesIn($directory);

            // RogueException is discovered even though no provider lists it, and
            // the interface is excluded. That is the whole failure mode: the real
            // test's assertSame against the provider cannot then match, so an
            // unguarded class added to src/Exception/ turns the suite red.
            self::assertSame(['KnownException', 'RogueException'], $found);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * The same completeness check, against a class one directory deeper.
     *
     * This is the half the one-level `glob('*.php')` did not have, and it is
     * pinned as its own test rather than folded into the one above so that a
     * regression to one level names itself. The mutation was executed: with the
     * walk reading a single level, `NestedRogueException` is absent from
     * `$found`, this assertion fails, and — the part that matters — the real
     * completeness check against src/Exception/ stays green while an unguarded
     * class sits in the shipped tree.
     *
     * A temporary directory again, never a throwaway file written into
     * src/Exception/: an interrupted run must not be able to leave a stray
     * class in the package. The check compares basenames and never autoloads,
     * so a fixture directory is a faithful stand-in for the real one.
     */
    public function testTheCompletenessCheckReachesAClassInASubdirectory(): void
    {
        $directory = sys_get_temp_dir() . '/vpos-exception-nested-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($directory . '/Nested', 0o700, true));

        try {
            file_put_contents($directory . '/KnownException.php', '');
            file_put_contents($directory . '/Nested/NestedRogueException.php', '');
            file_put_contents($directory . '/Nested/VposNestedInterface.php', '');

            self::assertSame(
                ['KnownException', 'NestedRogueException'],
                $this->exceptionClassNamesIn($directory),
                'A subdirectory must not exempt an exception class from the completeness check.',
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * Removes a fixture directory and everything under it.
     *
     * Recursive because the nested fixture above is, and because a cleanup that
     * reads one level would leave the temporary tree behind on every run — the
     * same flatness assumption in its least interesting form.
     */
    private function removeDirectory(string $directory): void
    {
        $entries = glob($directory . '/*');

        if ($entries !== false) {
            foreach ($entries as $entry) {
                if (is_dir($entry)) {
                    $this->removeDirectory($entry);

                    continue;
                }

                unlink($entry);
            }
        }

        rmdir($directory);
    }

    /**
     * Basenames of every exception class file under $directory, recursively,
     * sorted.
     *
     * A pure function of the path so its own failure mode is testable against a
     * fixture directory. Compares basenames only and never autoloads.
     *
     * **Recursive, and that is not speculative structure — it is the hole this
     * walk used to have.** `glob('*.php')` reads one level, and the class
     * docblock above describes exactly what that costs: a class dropped into
     * src/Exception/ that no provider lists is exempt from the marker check,
     * the final check and the credential guard simultaneously. A one-level
     * glob grants that same exemption to any class in a subdirectory, for free
     * and silently. It was measured, not reasoned about: a non-final class not
     * implementing the marker interface was placed at
     * `src/Exception/<sub>/Probe.php` and the entire suite stayed green,
     * walking past CONVENTIONS.md §8's ban on a second non-final class in src/
     * without a word.
     *
     * Every exception is expected directly in src/Exception/ — CONVENTIONS.md
     * §5's hierarchy names them as one flat set with no sub-namespace anywhere
     * in it — so this recursion is expected to find nothing extra. That is the
     * point: the cost of walking a tree that should stay flat is one recursive
     * call, and the cost of not walking it is an unguarded exception class.
     * The self-test below pins the recursion against a nested fixture so it
     * cannot quietly regress to one level.
     *
     * @return list<string>
     */
    private function exceptionClassNamesIn(string $directory): array
    {
        $entries = glob($directory . '/*');

        if ($entries === false) {
            return [];
        }

        $names = [];

        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                foreach ($this->exceptionClassNamesIn($entry) as $nested) {
                    $names[] = $nested;
                }

                continue;
            }

            if (!str_ends_with($entry, '.php')) {
                continue;
            }

            $basename = basename($entry, '.php');

            if (str_ends_with($basename, 'Interface')) {
                continue;
            }

            $names[] = $basename;
        }

        sort($names);

        return $names;
    }

    /**
     * The parent is asserted as the DIRECT parent, by reflection.
     *
     * is_a() was too weak here, and provably so: it accepts any transitive
     * ancestor, so detaching DeclinedException from ApiException entirely and
     * reparenting it on RuntimeException left the whole suite green. A consumer
     * writing catch (ApiException) to handle every gateway-answered failure
     * would have silently stopped catching declines. Only pinning the direct
     * parent fixes the shape of the tree rather than just its root.
     *
     * @param class-string<VposExceptionInterface> $class
     * @param class-string                         $parent
     */
    #[DataProvider('exceptionClasses')]
    public function testImplementsTheMarkerInterfaceAndExtendsItsDirectParent(
        string $class,
        string $parent,
    ): void {
        self::assertContains(VposExceptionInterface::class, class_implements($class));

        $actualParent = (new ReflectionClass($class))->getParentClass();

        self::assertNotFalse(
            $actualParent,
            $class . ' must extend ' . $parent . ', but it has no parent class at all.',
        );
        self::assertSame($parent, $actualParent->getName());
    }

    /**
     * @param class-string<VposExceptionInterface> $class
     */
    #[DataProvider('exceptionClasses')]
    public function testIsFinalUnlessItIsTheApiExceptionBase(string $class, string $parent): void
    {
        unset($parent);

        $reflection = new ReflectionClass($class);

        if ($class === ApiException::class) {
            self::assertFalse($reflection->isFinal(), 'ApiException is the hierarchy extension point.');
            self::assertTrue(
                $reflection->getConstructor()?->isFinal() ?? false,
                'ApiException::__construct must be final so subclasses share one signature.',
            );

            return;
        }

        self::assertTrue($reflection->isFinal(), $class . ' must be final.');
    }

    /**
     * A caller must be able to catch every failure from this package with one
     * catch block, and that block must not catch anything foreign.
     */
    public function testEveryExceptionIsCaughtByTheMarkerInterface(): void
    {
        /** @var list<VposExceptionInterface> $thrown Widened so the count stays a runtime fact. */
        $thrown = [
            ConfigurationException::blankCredential('username'),
            ValidationException::timeoutOutOfRange(1201),
            TransportException::requestFailed('InitPayment', RuntimeException::class, new RuntimeException('socket')),
            IndeterminateStateException::afterTransportFailure('RefundPayment', null, new RuntimeException('timeout')),
            ApiException::fromResponse('GetPaymentDetails', '05', 'Incorrect Parameters'),
            AuthenticationException::fromResponse('InitPayment', 20, 'Incorrect Username and Password'),
            DeclinedException::fromResponse('MakeBindingPayment', '0116', 'Not enough money'),
            DuplicateOrderException::fromResponse('InitPayment', '01', 'Order already exists'),
            GatewayFaultException::fromFaultEnvelope('GetPaymentDetails', 500, 'An error has occurred.'),
            SerializationException::malformedXml('GetTransactionList', 'unexpected root element'),
        ];

        self::assertCount(10, $thrown);

        foreach ($thrown as $exception) {
            try {
                throw $exception;
            } catch (VposExceptionInterface $caught) {
                self::assertSame($exception, $caught);
            }
        }

        // ...and nothing foreign. The docblock above claims both halves, so both
        // are asserted: a native SPL exception must not carry the marker.
        self::assertNotContains(VposExceptionInterface::class, class_implements(RuntimeException::class));
        self::assertNotContains(VposExceptionInterface::class, class_implements(LogicException::class));
    }

    /**
     * Retrying an operation whose outcome is unknown can capture or refund
     * twice. A caller catching TransportException to retry must not catch this.
     *
     * Asserted against the runtime ancestor list deliberately. is_a(),
     * instanceof, ReflectionClass::isSubclassOf() and a class-string comparison
     * all resolve statically when given a literal class name, so the analyser
     * discharges them and the guard silently stops guarding. class_parents()
     * returns a plain array the analyser cannot fold. Do not "simplify" this
     * back to instanceof or isSubclassOf().
     */
    public function testIndeterminateStateIsNotATransportException(): void
    {
        self::assertNotContains(
            TransportException::class,
            class_parents(IndeterminateStateException::class),
            'IndeterminateStateException must stay a sibling of TransportException, '
            . 'never a subtype. A caller catching TransportException to retry must '
            . 'not be able to swallow it.',
        );
    }

    /**
     * An ApiException means the gateway gave a business answer. A fault means
     * it gave none: the ASP.NET envelope carries no ResponseCode at all
     * (CONVENTIONS.md §4.2), and ApiException's final constructor requires
     * one, so a subclass could only exist by synthesising a code the gateway
     * never sent. responseCode() would then publish a fabricated value
     * indistinguishable from a real one.
     *
     * Asserted against class_parents() for the reason the sibling guard above
     * records: is_a(), instanceof, isSubclassOf() and a class-string comparison
     * all fold statically on a literal class name, so the analyser discharges
     * them and the guard stops guarding. Do not "simplify" this.
     */
    public function testGatewayFaultIsNotAnApiException(): void
    {
        self::assertNotContains(
            ApiException::class,
            class_parents(GatewayFaultException::class),
            'GatewayFaultException must stay a sibling of ApiException, never a '
            . 'subtype: no response code came off the wire, and inheriting the '
            . 'accessor would mean fabricating one.',
        );

        // The other half of the claim: it is still a RuntimeException, so the
        // marker is not the only way to catch it. class_parents() again, for the
        // same reason.
        self::assertContains(RuntimeException::class, class_parents(GatewayFaultException::class));
    }

    /**
     * Exception messages reach logs. Nothing here may accept a value that would
     * put a credential or a card datum into one.
     */
    /**
     * Public properties are inspected as well as parameters, promoted or not.
     *
     * A parameter-only guard is evaded by assigning a benignly-named argument to
     * a public readonly property in the constructor body: the parameter passes,
     * the property carries the datum, and the guard never sees it.
     */
    public function testNoPublicMethodOrPropertyExposesACredentialOrCardDatum(): void
    {
        foreach (self::exceptionClasses() as [$class]) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    self::assertNull(
                        $this->forbiddenTokenIn($parameter->getName()),
                        sprintf(
                            '%s::%s() must not accept $%s.',
                            $class,
                            $method->getName(),
                            $parameter->getName(),
                        ),
                    );
                }
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                self::assertNull(
                    $this->forbiddenTokenIn($property->getName()),
                    sprintf('%s::$%s must not be public.', $class, $property->getName()),
                );
            }
        }
    }

    /**
     * The guard above is only worth having if it can be shown to catch something.
     *
     * Its predecessor compared names for equality against a fixed list, so
     * every compound name walked straight through: $cardPan and $responseBody
     * both passed clean, and $responseBody is precisely the raw-body channel
     * CONVENTIONS.md §5 forbids. These cases pin the matcher itself.
     */
    public function testForbiddenTokenMatcherCatchesCompoundNames(): void
    {
        self::assertSame('cardpan', $this->forbiddenTokenIn('cardPan'), 'Derived from the manifest field CardPan.');
        self::assertSame('pan', $this->forbiddenTokenIn('pan'), 'The residual stem still fires on a bare name.');
        self::assertSame('approvalcode', $this->forbiddenTokenIn('approvalCode'));
        self::assertSame('authcode', $this->forbiddenTokenIn('authCode'));
        self::assertSame('expiration', $this->forbiddenTokenIn('expirationDate'));
        self::assertSame('expiration', $this->forbiddenTokenIn('expiration'));
        self::assertSame('cardholdername', $this->forbiddenTokenIn('cardHolderName'));
        self::assertSame('pin', $this->forbiddenTokenIn('pin'));
        self::assertSame('content', $this->forbiddenTokenIn('responseContent'));
        self::assertSame('content', $this->forbiddenTokenIn('messageContents'));
        self::assertSame('body', $this->forbiddenTokenIn('responseBody'));
        self::assertSame('password', $this->forbiddenTokenIn('user_password'));
        self::assertSame('raw', $this->forbiddenTokenIn('rawResponse'));

        // Permitted by CONVENTIONS.md §5 and must not trip the guard.
        self::assertNull($this->forbiddenTokenIn('responseCode'));
        self::assertNull($this->forbiddenTokenIn('responseMessage'));
        self::assertNull($this->forbiddenTokenIn('operation'));
    }

    /**
     * The derived half of the list must actually derive something.
     *
     * This is the failure mode a hand-maintained list did not have: if the
     * manifest moves, its schema changes, or the selection rules are edited
     * into uselessness, manifestSensitiveTokens() returns an empty array and
     * the guard silently shrinks to its residual half. Nothing else in this
     * file would notice — every parameter in src/Exception/ is clean, so a
     * weakened guard and a working one produce identical green runs.
     *
     * The names asserted are CONVENTIONS.md §6's own list plus `Password`,
     * transcribed from the project document rather than from the manifest, so
     * the two must agree for this to pass.
     */
    public function testTheSensitiveTokensAreDerivedFromTheManifest(): void
    {
        $derived = $this->manifestSensitiveTokens();

        self::assertSame(['approvalcode', 'cardnumber', 'cardpan', 'expdate', 'password', 'ssn'], $derived);

        self::assertNotContains(
            'cardholderid',
            $derived,
            'A CardHolderID is a binding token, not card data; src/Request/ names it in a §6-compliant rejection.',
        );
    }

    /**
     * The tokens that are not manifest field names, and so cannot be derived.
     *
     * This guard's hand-maintained list was replaced by one read from
     * `docs/api-reference/api-surface.json`, so that a sensitive field the
     * bank adds upstream is covered without anyone remembering to extend an
     * array. What follows is the residue: every token the manifest cannot
     * supply, because it names no field of any model.
     *
     * Three groups, kept apart because they are three different rules.
     *
     * **Credential stems.** 'password' *is* a manifest field and arrives from
     * the derivation; 'pass', 'pwd', 'secret', 'token', 'apikey' and
     * 'credential' are not fields at all — they are the names a parameter
     * would plausibly be given. CONVENTIONS.md §6 puts them under the same
     * handling.
     *
     * **The raw-body channel.** 'body', 'payload', 'raw' and 'content' name no
     * field anywhere; they name the thing CONVENTIONS.md §5 forbids an
     * exception to carry, since a response body may hold card data. This is
     * the group the manifest can never supply, and losing it would have
     * reopened the exact hole this test's own docblock records $responseBody
     * walking through.
     *
     * **Card data the API does not declare.** 'cvv', 'cvc', 'pin',
     * 'authcode', 'expiry', 'expiration' and 'cardholdername' are absent from
     * the manifest because the vPOS API never accepts or returns them — the
     * gateway holds the card, not this SDK. A parameter named for one would
     * still be a defect, and the API's silence is not a reason to permit it.
     * 'pan' stays here too: the manifest declares `CardPan`, so the derivation
     * supplies 'cardpan', but a parameter named plainly $pan would slip past
     * that and is the shorter, likelier spelling.
     *
     * @var list<string>
     */
    private const array TOKENS_NO_MANIFEST_FIELD_SUPPLIES = [
        'pass', 'pwd', 'secret', 'token', 'apikey', 'credential',
        'body', 'payload', 'raw', 'content',
        'pan', 'cvv', 'cvc', 'pin', 'authcode', 'expiry', 'expiration',
        'cardholdername',
    ];

    /**
     * Returns the offending token, or null when the name is clean.
     *
     * Matched as a substring, not by equality, so compound names cannot evade
     * it. "response" is deliberately absent: as a substring it would fire on
     * $responseCode and $responseMessage, which CONVENTIONS.md §5 explicitly
     * permits. "body" and "raw" cover the channel that matters.
     *
     * The derived tokens are checked before the residual ones, so where both
     * match, the reported token is the manifest field that explains it —
     * $cardPan reports 'cardpan', naming the upstream field `CardPan`, while a
     * bare $pan still reports 'pan' from the residual list. That ordering is
     * not cosmetic: the returned token is what tells the next reader which rule
     * fired, and for a name that maps onto a real field, the field is the
     * better answer.
     *
     * 'pin' is deliberately unanchored, so it also matches $mapping and
     * $shipping. That is the intended trade: a false positive is a loud test
     * failure at the moment someone adds the name, cheap to diagnose and
     * trivial to resolve, while a missed PIN is silent and permanent. Do not
     * anchor it.
     */
    private function forbiddenTokenIn(string $parameterName): ?string
    {
        $normalised = strtolower(str_replace('_', '', $parameterName));

        foreach ([...$this->manifestSensitiveTokens(), ...self::TOKENS_NO_MANIFEST_FIELD_SUPPLIES] as $token) {
            if (str_contains($normalised, $token)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * The sensitive field names the manifest declares, lowercased.
     *
     * Derived at test time from the specification of record, so a sensitive
     * field added upstream is covered on the next regeneration. The selection
     * rules are CONVENTIONS.md §6's own list — a PAN, a card number, an
     * expiry, an approval code, an SSN — matched against each field name's
     * camel-case words, which is what keeps `CardHolderID` out: a binding
     * token is not a PAN, and src/Request/ names it in a §6-compliant
     * rejection.
     *
     * `Password` arrives from here rather than from the residual list above,
     * because it genuinely is a field of every request model.
     *
     * The derivation is duplicated rather than shared with
     * tests/Support/NoSensitiveManifestFieldInMessageTest, on the reasoning
     * tests/Money's guard records: a guard that borrows another guard's helper
     * fails open the day that helper is refactored for the other guard's
     * convenience. The two guard different things — that one scans message
     * text, this one scans parameter and property names — and neither should
     * be able to weaken the other.
     *
     * @return list<string>
     */
    private function manifestSensitiveTokens(): array
    {
        $raw = file_get_contents(self::MANIFEST);

        self::assertIsString($raw, sprintf('Could not read the manifest at %s.', self::MANIFEST));

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertIsArray($decoded['models'] ?? null);

        $tokens = [];

        foreach ($decoded['models'] as $model) {
            self::assertIsArray($model);
            self::assertIsArray($model['fields'] ?? null);

            foreach ($model['fields'] as $field) {
                self::assertIsArray($field);
                self::assertIsString($field['Name'] ?? null);

                // Enum tables carry members, not fields: a Name with no Type.
                if (!array_key_exists('Type', $field)) {
                    continue;
                }

                if (!$this->isSensitiveFieldName($field['Name'])) {
                    continue;
                }

                $tokens[strtolower(str_replace('_', '', $field['Name']))] = true;
            }
        }

        $derived = array_keys($tokens);
        sort($derived);

        self::assertNotSame([], $derived, 'The manifest yielded no sensitive field names; the guard would be vacuous.');

        return $derived;
    }

    /**
     * Whether a manifest field name is one CONVENTIONS.md §6 protects.
     *
     * Word-level and conjunctive. `CardNumber` matches because it carries both
     * 'card' and 'number'; `CardHolderID` carries 'card' alone and does not.
     * `ExchangeRate` splits to exchange|rate, so the expiry stem 'exp' does not
     * reach it, and `ApprovedAmount` carries 'approved' rather than 'approval'
     * — an approved amount is a sum of money, not an approval code.
     *
     * `Password` is included here, unlike in the message-text guard: this
     * checks *parameter names*, where an exception accepting $password is a
     * defect however it is spelled, while that one checks message text, where
     * §5 requires the credential field to be named.
     */
    private function isSensitiveFieldName(string $name): bool
    {
        $split = preg_split('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|_/', $name);

        self::assertNotFalse($split, sprintf('The word-splitting pattern failed on %s.', $name));

        $words = array_map(strtolower(...), $split);

        $rules = [
            [['pan']],
            [['card'], ['number', 'no', 'num']],
            [['exp', 'expdate', 'expiry', 'expiration', 'expires']],
            [['approval']],
            [['ssn', 'socialsecurity']],
            [['password', 'pwd']],
        ];

        foreach ($rules as $groups) {
            $matched = true;

            foreach ($groups as $group) {
                if (array_intersect($group, $words) === []) {
                    $matched = false;

                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Response codes arrive as int from InitPayment and string elsewhere.
     * Narrowing this to one type would break half the endpoints.
     */
    public function testApiExceptionAcceptsBothResponseCodeTypes(): void
    {
        $fromInt = ApiException::fromResponse('InitPayment', 20, 'Incorrect Username and Password');
        $fromString = ApiException::fromResponse('CancelPayment', '20', 'Incorrect Username and Password');

        self::assertSame(20, $fromInt->responseCode());
        self::assertSame('20', $fromString->responseCode());

        $returnType = (new ReflectionMethod(ApiException::class, 'responseCode'))->getReturnType();

        // Stated positively: assertNotInstanceOf(ReflectionNamedType::class, ...)
        // was also satisfied by null, so deleting the return type outright passed.
        self::assertInstanceOf(ReflectionUnionType::class, $returnType, 'responseCode() must stay a union type.');

        // Members are collected and sorted rather than compared against the
        // stringified type: ReflectionType's string cast is deprecated, and PHP
        // canonicalises union order when stringifying anyway ("string|int" here,
        // whatever the declaration says), which would make the literal fragile.
        $typeNames = [];

        foreach ($returnType->getTypes() as $type) {
            self::assertInstanceOf(ReflectionNamedType::class, $type);

            $typeNames[] = $type->getName();
        }

        sort($typeNames);

        self::assertSame(['int', 'string'], $typeNames);
    }
}
