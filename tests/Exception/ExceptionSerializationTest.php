<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Exception;

use function count;

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
use DavitVardanyan\AmeriabankVpos\Support\ExceptionState;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;

use function sprintf;

use Throwable;

/**
 * A failed payment is queued, and the queue serialises.
 *
 * Every parameter that carries a request or response body is marked
 * `#[\SensitiveParameter]`, which closed a measured leak: the merged
 * credential payload was reaching print_r() through a stack frame. The engine
 * implements that marking by putting a SensitiveParameterValue in the trace,
 * and it refuses to serialise one — so every exception the transport throws
 * became unserialisable, including an ordinary response code 20 decline. A
 * merchant queueing a failed payment job took a fatal in the worker, with a
 * message naming a PHP internal class and nothing about payments.
 *
 * These tests pin the fix: the state is scrubbed rather than refused. They are
 * written so that removing the scrubbing brings the fatal back (the round-trip
 * assertions) and so that carrying the trace arguments instead publishes a
 * canary (the leak assertions). Neither half passes on its own.
 */
#[CoversClass(ApiException::class)]
#[CoversClass(AuthenticationException::class)]
#[CoversClass(ConfigurationException::class)]
#[CoversClass(DeclinedException::class)]
#[CoversClass(DuplicateOrderException::class)]
#[CoversClass(ExceptionState::class)]
#[CoversClass(GatewayFaultException::class)]
#[CoversClass(IndeterminateStateException::class)]
#[CoversClass(SerializationException::class)]
#[CoversClass(TransportException::class)]
#[CoversClass(ValidationException::class)]
final class ExceptionSerializationTest extends TestCase
{
    /**
     * Nine characters, matching the transport's own canary.
     *
     * A long marker can survive redaction in pieces — the card rule keeps
     * first-six and last-four — so a canary must be short enough that any
     * surviving fragment is the whole thing. A negative assertion whose needle
     * is longer than what a defect would publish asserts nothing.
     */
    private const string CANARY = 'pw-canary';

    /**
     * Second column is whether the exception was built over a `previous`, which
     * is what chainDropped() must report after a round trip.
     *
     * @return list<array{class-string<VposExceptionInterface>, bool}>
     */
    public static function throwableClasses(): array
    {
        return [
            [ApiException::class, false],
            [AuthenticationException::class, false],
            [ConfigurationException::class, true],
            [DeclinedException::class, false],
            [DuplicateOrderException::class, false],
            [GatewayFaultException::class, false],
            [IndeterminateStateException::class, true],
            [SerializationException::class, true],
            [TransportException::class, true],
            [ValidationException::class, false],
        ];
    }

    /**
     * Without this, the provider is an allowlist and a new exception class ships
     * unserialisable — which is precisely the defect this file exists for, and it
     * would reappear silently. Mirrors the completeness guard in
     * ExceptionHierarchyTest, against the same directory.
     */
    public function testTheProviderCoversEveryExceptionClassOnDisk(): void
    {
        $onDisk = $this->exceptionBasenamesIn(__DIR__ . '/../../src/Exception');

        $inProvider = [];

        foreach (self::throwableClasses() as [$class]) {
            $separator = strrpos($class, '\\');
            $inProvider[] = $separator === false ? $class : substr($class, $separator + 1);
        }

        sort($onDisk);
        sort($inProvider);

        self::assertNotSame([], $onDisk, 'No classes found on disk: the glob path is wrong.');
        self::assertSame($onDisk, $inProvider);
    }

    /**
     * The same completeness check must reach a class one directory deeper.
     *
     * This walk was `glob('src/Exception/*.php')`, which reads one level, so an
     * exception class in a subdirectory was absent from `$onDisk`, matched the
     * provider by omission, and shipped without ever being round-tripped. The
     * mutation was executed against the old form: a class placed at
     * `src/Exception/<sub>/Probe.php` left the whole suite green.
     *
     * Asserted against a temporary directory rather than by writing a throwaway
     * class into src/Exception/, so an interrupted run cannot leave a stray file
     * in the shipped tree. The walk compares basenames and never autoloads,
     * which is what makes a fixture directory a faithful stand-in.
     */
    public function testTheOnDiskScanReachesAClassInASubdirectory(): void
    {
        $directory = sys_get_temp_dir() . '/vpos-serialization-nested-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($directory . '/Nested', 0o700, true));

        try {
            file_put_contents($directory . '/KnownException.php', '');
            file_put_contents($directory . '/Nested/NestedRogueException.php', '');
            file_put_contents($directory . '/Nested/VposNestedInterface.php', '');

            self::assertSame(
                ['KnownException', 'NestedRogueException'],
                $this->exceptionBasenamesIn($directory),
                'A subdirectory must not exempt an exception class from the round-trip guarantee.',
            );
        } finally {
            $this->removeDirectory($directory);
        }
    }

    /**
     * Basenames of every exception class file under $directory, recursively.
     *
     * A pure function of the path, so the recursion above can be pinned against
     * a fixture directory. Interfaces are excluded because the provider lists
     * classes; names are compared, nothing is autoloaded.
     *
     * Deliberately not shared with ExceptionHierarchyTest's near-identical walk.
     * A guard that borrows another guard's walker fails open the day that walker
     * is refactored for the other guard's convenience — the reasoning
     * tests/Money's guard records, and these two guards check different
     * properties of the same directory.
     *
     * @return list<string>
     */
    private function exceptionBasenamesIn(string $directory): array
    {
        $entries = glob($directory . '/*');

        self::assertNotFalse($entries, sprintf('Could not list %s.', $directory));

        $names = [];

        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                foreach ($this->exceptionBasenamesIn($entry) as $nested) {
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
     * Removes a fixture directory and everything under it.
     *
     * Recursive because the fixture above is, and because a one-level cleanup
     * would leave the temporary tree behind on every run.
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
     * The whole guarantee, per class, in one pass.
     *
     * The two preconditions are the point of the test: they prove the trace of
     * the exception under test really does hold both a SensitiveParameterValue —
     * the thing the engine refuses — and the canary in the clear, through the
     * unmarked parameter beside it. Without them a green result would only mean
     * the fixture was harmless.
     *
     * @param class-string<VposExceptionInterface> $class
     */
    #[DataProvider('throwableClasses')]
    public function testAThrownFailureSurvivesARoundTripWithNothingSensitiveInIt(
        string $class,
        bool $expectedChainDropped,
    ): void {
        $original = $this->builtWithTheCanaryInTheTrace($class, self::CANARY, self::CANARY);
        $rendered = print_r($original->getTrace(), true);

        self::assertStringContainsString(
            'SensitiveParameterValue',
            $rendered,
            'The fixture must reproduce the frame the engine refuses to serialise.',
        );
        self::assertStringContainsString(
            self::CANARY,
            $rendered,
            'The fixture must also carry the canary in the clear, or the leak half asserts nothing.',
        );
        self::assertNull($original->chainDropped(), 'An object that was never restored has no answer.');

        $payload = serialize($original);

        self::assertStringNotContainsString(self::CANARY, $payload, 'serialize()');

        $restored = unserialize($payload);

        self::assertInstanceOf($class, $restored);
        self::assertSame($original->getMessage(), $restored->getMessage());
        self::assertSame($original->getFile(), $restored->getFile());
        self::assertNull($restored->getPrevious(), 'The chain must not survive.');
        self::assertSame($expectedChainDropped, $restored->chainDropped());
        self::assertStringNotContainsString(self::CANARY, print_r($restored, true), 'print_r()');
        self::assertNotSame([], $restored->getTrace(), 'The call path must survive.');

        foreach ($restored->getTrace() as $frame) {
            self::assertArrayNotHasKey('args', $frame);
        }
    }

    /**
     * The throw site, which a restored trace would otherwise contradict.
     *
     * Asserted apart from the round-trip test because it needs two preconditions
     * that only hold when they are stated: every fixture is built by a factory
     * inside src/Exception/, so the original's file is not this file, and the
     * line `unserialize()` is called on is not the line the factory constructed
     * on. Without both, a mutation dropping the file or the line from the state
     * would be invisible — the values would coincide.
     *
     * @param class-string<VposExceptionInterface> $class
     */
    #[DataProvider('throwableClasses')]
    public function testARestoredExceptionStillPointsAtWhereItWasThrown(
        string $class,
        bool $expectedChainDropped,
    ): void {
        unset($expectedChainDropped);

        $original = $this->builtWithTheCanaryInTheTrace($class, self::CANARY, self::CANARY);
        $payload = serialize($original);

        self::assertNotSame(__FILE__, $original->getFile());

        $restoreLine = __LINE__ + 1;
        $restored = unserialize($payload);

        self::assertNotSame(
            $restoreLine,
            $original->getLine(),
            'Move the unserialize() call: it coincides with the throw line, so this test cannot fail.',
        );
        self::assertInstanceOf($class, $restored);
        self::assertSame($original->getFile(), $restored->getFile());
        self::assertSame($original->getLine(), $restored->getLine());
    }

    /**
     * A restored exception is still an exception: throwable, and caught by the
     * marker interface as well as by its own type.
     *
     * @param class-string<VposExceptionInterface> $class
     */
    #[DataProvider('throwableClasses')]
    public function testARestoredExceptionIsStillThrowableAndCatchableAsItself(
        string $class,
        bool $expectedChainDropped,
    ): void {
        unset($expectedChainDropped);

        $restored = unserialize(serialize(
            $this->builtWithTheCanaryInTheTrace($class, self::CANARY, self::CANARY),
        ));

        self::assertInstanceOf($class, $restored);

        try {
            throw $restored;
        } catch (VposExceptionInterface $caught) {
            self::assertSame($restored, $caught);
            self::assertInstanceOf($class, $caught);
        }
    }

    /**
     * The call path survives whole and the arguments do not, key by key.
     *
     * assertSame on the entire frame, not a key-by-key spot check: the frame is
     * an ordered array, so this pins the key set, the order and the values at
     * once. Dropping any one of the five kept keys, unwrapping the array_flip()
     * that makes the filter key-based, or letting `args` through all fail here.
     */
    public function testARestoredTraceKeepsTheCallPathAndDropsTheArguments(): void
    {
        $line = __LINE__ + 1;
        $original = ApiException::fromResponse('GetPaymentDetails', '05', 'Incorrect Parameters');

        self::assertArrayHasKey(
            'args',
            $this->firstFrameOf($original),
            'The original frame must carry arguments, or there is nothing to drop.',
        );

        $restored = unserialize(serialize($original));

        self::assertInstanceOf(ApiException::class, $restored);

        // Every frame, not just the top one. Filtering is one-to-one — it drops
        // keys, never frames — so a restored trace that is shorter than the
        // original has lost part of the call path, and a truncated trace reads
        // like a shallower stack rather than like a defect.
        self::assertGreaterThan(1, count($original->getTrace()), 'The fixture must be more than one frame deep.');
        self::assertSameSize($original->getTrace(), $restored->getTrace());

        $frame = $this->firstFrameOf($restored);

        // The key set and its order first, then the values. The frame goes
        // through firstFrameOf() so it arrives as a plain array: the shape
        // PHPStan carries for getTrace() puts `function` at index 0, which is not
        // the order PHP builds a frame in, and it folds any comparison against
        // the real order to a constant false.
        self::assertSame(['file', 'line', 'function', 'class', 'type'], array_keys($frame));
        self::assertSame(__FILE__, $frame['file']);
        self::assertSame($line, $frame['line']);
        self::assertSame('fromResponse', $frame['function']);
        self::assertSame(ApiException::class, $frame['class']);
        self::assertSame('::', $frame['type']);
    }

    /**
     * The topmost stack frame of $thrown, as a plain array.
     *
     * @return array<array-key, mixed>
     */
    private function firstFrameOf(Throwable $thrown): array
    {
        $trace = $thrown->getTrace();

        self::assertNotSame([], $trace, 'The fixture must have a trace.');

        return $trace[0];
    }

    /**
     * The response code keeps its wire type across the boundary.
     *
     * CONVENTIONS.md §4.3: InitPayment answers int, every other endpoint
     * answers string, and failure code 20 arrives as `int 20` and as `string
     * "20"` depending on which endpoint was asked. A round trip that coerced
     * one into the other would publish a value the gateway never sent, and
     * `"00"` would come back as `0`.
     *
     * assertSame, never assertEquals: `20 == "20"` and `"00" == 0` are both
     * true, so a loose comparison is exactly blind to the defect.
     *
     * @return list<array{int|string}>
     */
    public static function responseCodes(): array
    {
        return [[1], [20], ['00'], ['20'], ['0-1'], ['0151017']];
    }

    #[DataProvider('responseCodes')]
    public function testTheResponseCodeKeepsItsWireType(int|string $code): void
    {
        $restored = unserialize(serialize(
            ApiException::fromResponse('InitPayment', $code, 'Incorrect Username and Password'),
        ));

        self::assertInstanceOf(ApiException::class, $restored);
        self::assertSame($code, $restored->responseCode());
        self::assertSame('InitPayment', $restored->operation());
        self::assertSame('Incorrect Username and Password', $restored->responseMessage());
    }

    /**
     * The routine business decline, end to end. This is the case the finding was
     * filed about: not an exotic transport fault, but response code 20 on a
     * payment a merchant queued.
     */
    public function testTheOrdinaryDeclineRoundTripsWithItsCodeAndMessage(): void
    {
        $restored = unserialize(serialize(
            DeclinedException::fromResponse('MakeBindingPayment', '0116', 'Not enough money'),
        ));

        self::assertInstanceOf(DeclinedException::class, $restored);
        self::assertSame('MakeBindingPayment', $restored->operation());
        self::assertSame('0116', $restored->responseCode());
        self::assertSame('Not enough money', $restored->responseMessage());
        self::assertSame(
            'MakeBindingPayment failed with response code 0116: Not enough money',
            $restored->getMessage(),
        );
    }

    /**
     * TransportException carries only its operation, and it is the field a
     * retrying job reads first.
     *
     * The failing client's class name is not asserted separately: requestFailed()
     * puts it in the message, and the message is asserted whole.
     */
    public function testTheTransportOperationAndMessageSurvive(): void
    {
        $original = TransportException::requestFailed(
            'GetPaymentDetails',
            RuntimeException::class,
            new RuntimeException('socket'),
        );

        $restored = unserialize(serialize($original));

        self::assertInstanceOf(TransportException::class, $restored);
        self::assertSame('GetPaymentDetails', $restored->operation());
        self::assertSame(
            'The GetPaymentDetails request could not be completed: ' . RuntimeException::class,
            $restored->getMessage(),
        );
    }

    public function testTheFaultKeepsItsOperationStatusAndMessage(): void
    {
        $restored = unserialize(serialize(
            GatewayFaultException::fromFaultEnvelope('GetPaymentDetails', 500, 'An error has occurred.'),
        ));

        self::assertInstanceOf(GatewayFaultException::class, $restored);
        self::assertSame('GetPaymentDetails', $restored->operation());
        self::assertSame(500, $restored->statusCode());
        self::assertSame('An error has occurred.', $restored->faultMessage());
    }

    /**
     * The PaymentID is the one field a restored IndeterminateStateException
     * cannot do without: its entire instruction is to reconcile with
     * GetPaymentDetails, and a job that lost the identifier cannot.
     */
    public function testTheIndeterminatePaymentIdSurvives(): void
    {
        $restored = unserialize(serialize(
            IndeterminateStateException::afterTransportFailure('RefundPayment', 'PID-1', new RuntimeException('t')),
        ));

        self::assertInstanceOf(IndeterminateStateException::class, $restored);
        self::assertSame('RefundPayment', $restored->operation());
        self::assertSame('PID-1', $restored->paymentId());
    }

    /**
     * And a null PaymentID stays null rather than becoming an empty string a
     * caller might interpolate into a reconciliation call.
     */
    public function testAnAbsentIndeterminatePaymentIdStaysNull(): void
    {
        $restored = unserialize(serialize(
            IndeterminateStateException::afterTransportFailure('ConfirmPayment', null, new RuntimeException('t')),
        ));

        self::assertInstanceOf(IndeterminateStateException::class, $restored);
        self::assertNull($restored->paymentId());
        self::assertSame('ConfirmPayment', $restored->operation());
    }

    /**
     * causedByJson() must answer the same before and after.
     *
     * It used to read getPrevious(), which the round trip drops, so it would have
     * answered true on the way in and false on the way out for the same failure.
     * That is why the flag is now derived once, at construction.
     *
     * @return list<array{SerializationException, bool}>
     */
    public static function serializationFailures(): array
    {
        return [
            [SerializationException::malformedJson('InitPayment', new JsonException('Syntax error')), true],
            [SerializationException::malformedXml('GetTransactionList', 'unexpected root element'), false],
        ];
    }

    #[DataProvider('serializationFailures')]
    public function testCausedByJsonAnswersTheSameAfterARoundTrip(
        SerializationException $original,
        bool $expected,
    ): void {
        self::assertSame($expected, $original->causedByJson());

        $restored = unserialize(serialize($original));

        self::assertInstanceOf(SerializationException::class, $restored);
        self::assertSame($expected, $restored->causedByJson());
        self::assertSame($original->operation(), $restored->operation());
    }

    /**
     * A payload with every key missing restores a degraded object rather than
     * throwing.
     *
     * This is the divergence from Config\Credentials, which refuses to restore at
     * all. A restored decline is still a usable decline, and throwing out of
     * unserialize() inside a queue worker is the fatal this whole file exists to
     * remove — so a truncated or hand-edited payload must not be able to cause
     * one.
     *
     * __unserialize() is called directly, on an instance built without a
     * constructor, because that is exactly what the engine does. Calling it on a
     * constructed object would fail on the readonly fields instead.
     *
     * @param class-string<VposExceptionInterface> $class
     */
    #[DataProvider('throwableClasses')]
    public function testAnEmptyPayloadRestoresADegradedObjectAndDoesNotThrow(
        string $class,
        bool $expectedChainDropped,
    ): void {
        // The provider's flag describes the built exception, not a payload that
        // never carried the key. false here is the honest answer either way.
        unset($expectedChainDropped);

        $restored = $this->restoredFrom($class, []);

        self::assertSame('', $restored->getMessage());
        self::assertSame('', $restored->getFile());
        self::assertSame(0, $restored->getLine());
        self::assertSame([], $restored->getTrace());
        self::assertFalse($restored->chainDropped());
        self::assertNull($restored->getPrevious());
    }

    /**
     * And a payload whose every value is of the wrong type does the same.
     *
     * Each field is given a value that is plausible but wrong — a numeric string
     * where an int belongs, an int where a nullable string does, a truthy string
     * where a bool does. `chainDropped` is `'yes'`, which is truthy: the flag is
     * read with an identity comparison precisely so a value this package did not
     * write cannot be mistaken for one it did.
     *
     * @param class-string<VposExceptionInterface> $class
     */
    #[DataProvider('throwableClasses')]
    public function testAWrongTypedPayloadRestoresADegradedObjectAndDoesNotThrow(
        string $class,
        bool $expectedChainDropped,
    ): void {
        unset($expectedChainDropped);

        $restored = $this->restoredFrom($class, [
            'message' => 404,
            'file' => false,
            'line' => '31',
            'trace' => 'not a trace',
            'chainDropped' => 'yes',
            'operation' => 17,
            'responseCode' => ['00'],
            'responseMessage' => null,
            'faultMessage' => 1.5,
            'statusCode' => '500',
            'paymentId' => 7,
            'causedByJson' => 1,
        ]);

        self::assertSame('', $restored->getMessage());
        self::assertSame('', $restored->getFile());
        self::assertSame(0, $restored->getLine());
        self::assertSame([], $restored->getTrace());
        self::assertFalse($restored->chainDropped());

        if ($restored instanceof ApiException) {
            self::assertSame('', $restored->operation());
            self::assertSame('', $restored->responseCode());
            self::assertSame('', $restored->responseMessage());
        }

        if ($restored instanceof GatewayFaultException) {
            self::assertSame(0, $restored->statusCode());
            self::assertSame('', $restored->faultMessage());
        }

        if ($restored instanceof IndeterminateStateException) {
            self::assertNull($restored->paymentId());
        }

        if ($restored instanceof SerializationException) {
            self::assertFalse($restored->causedByJson());
        }
    }

    /**
     * A hand-edited trace is filtered on the way in as well as on the way out.
     *
     * The payload below is what an attacker with write access to a queue store
     * would craft: a frame carrying `args`, next to an entry that is not a frame
     * at all. Both are handled without a TypeError, and the canary does not
     * survive into anything a printer walks.
     */
    public function testATamperedTraceIsFilteredOnRestore(): void
    {
        $restored = $this->restoredFrom(ApiException::class, [
            'trace' => [
                'not a frame',
                ['file' => 'Worker.php', 'line' => 12, 'function' => 'run', 'args' => [self::CANARY]],
            ],
        ]);

        self::assertSame(
            [['file' => 'Worker.php', 'line' => 12, 'function' => 'run']],
            $restored->getTrace(),
        );
        self::assertStringNotContainsString(self::CANARY, print_r($restored, true));
    }

    /**
     * Restores $class from $data the way the engine does: no constructor, then
     * __unserialize().
     *
     * @param class-string<VposExceptionInterface> $class
     * @param array<array-key, mixed>              $data
     */
    private function restoredFrom(string $class, array $data): VposExceptionInterface
    {
        $restored = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertInstanceOf($class, $restored);

        // Through reflection rather than a direct call: __unserialize() is not on
        // the marker interface, and looking it up by name also asserts that every
        // class in the provider actually declares one.
        (new ReflectionMethod($class, '__unserialize'))->invoke($restored, $data);

        return $restored;
    }

    /**
     * Builds $class two frames deep, with the canary reachable through both
     * channels that matter.
     *
     * $sensitive is marked, so the engine replaces it in the trace with a
     * SensitiveParameterValue — the object serialize() refuses. $plain is not, so
     * the trace also holds the canary as an ordinary string argument, which
     * serialises perfectly well. The first proves the refusal this fix removes;
     * the second is what a trace carried into the payload would publish, and is
     * why the negative assertions in this file can fail.
     */
    private function builtWithTheCanaryInTheTrace(
        string $class,
        string $plain,
        #[SensitiveParameter]
        string $sensitive,
    ): VposExceptionInterface {
        self::assertSame($plain, $sensitive, 'Both channels must carry the same canary.');

        return match ($class) {
            ApiException::class => ApiException::fromResponse('GetPaymentDetails', '05', 'Incorrect Parameters'),
            AuthenticationException::class => AuthenticationException::fromResponse(
                'InitPayment',
                20,
                'Incorrect Username and Password',
            ),
            ConfigurationException::class => ConfigurationException::requestRejectedByClient(
                'InitPayment',
                RuntimeException::class,
                new RuntimeException('rejected'),
            ),
            DeclinedException::class => DeclinedException::fromResponse(
                'MakeBindingPayment',
                '0116',
                'Not enough money',
            ),
            DuplicateOrderException::class => DuplicateOrderException::fromResponse(
                'InitPayment',
                '01',
                'Order already exists',
            ),
            GatewayFaultException::class => GatewayFaultException::fromFaultEnvelope(
                'GetPaymentDetails',
                500,
                'An error has occurred.',
            ),
            IndeterminateStateException::class => IndeterminateStateException::afterTransportFailure(
                'RefundPayment',
                'PID-1',
                new RuntimeException('timed out'),
            ),
            SerializationException::class => SerializationException::malformedJson(
                'InitPayment',
                new JsonException('Syntax error'),
            ),
            TransportException::class => TransportException::requestFailed(
                'InitPayment',
                RuntimeException::class,
                new RuntimeException('socket'),
            ),
            ValidationException::class => ValidationException::timeoutOutOfRange(1201),
            default => self::fail('No fixture for ' . $class . '.'),
        };
    }
}
