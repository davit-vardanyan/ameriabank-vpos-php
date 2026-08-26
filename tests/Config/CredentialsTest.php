<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Config;

use function array_map;

use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use Error;

use function in_array;
use function ini_set;
use function is_array;
use function json_encode;
use function ob_get_clean;
use function ob_start;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function preg_replace;
use function print_r;

use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SensitiveParameterValue;

use function serialize;
use function sort;
use function sprintf;
use function str_contains;

use Throwable;

use function unserialize;
use function var_dump;
use function var_export;

/**
 * Credentials is the only class in this package that holds a secret, so this
 * file is where CONVENTIONS.md §6 is either true or decorative. Every
 * assertion below was written against a named mutation of
 * src/Config/Credentials.php, the mutation was applied and observed red, and
 * only then was the assertion kept.
 *
 * Every credential literal here is obviously fake and was invented for this
 * file. None of them came from .env, from a probe report, or from the sandbox.
 * This suite once shipped a live sandbox password into a test by reaching for a
 * "realistic" value, and the fix is not vigilance but the constants below:
 * there is no realistic value to reach for.
 *
 * Two habits are avoided throughout, because both would make this file look
 * like it tests a secret-handling class without testing one:
 *
 * - Asserting only that a channel does not contain the password. That is green
 *   for a channel that renders nothing at all, so every negative assertion here
 *   is paired with a positive one proving the channel really did render the
 *   object.
 * - Asserting that the redaction marker is present. `[redacted 24 chars]`
 *   contains no substring `[redacted]`, so that one happens to catch a marker
 *   carrying the length — but only by accident of the closing bracket. The
 *   invariance test below catches it on purpose, for any derived value.
 */
#[CoversClass(Credentials::class)]
#[UsesClass(ConfigurationException::class)]
final class CredentialsTest extends TestCase
{
    private const string CLIENT_ID = 'fake-client-id';

    private const string USERNAME = 'fake-username';

    private const string PASSWORD = 'fake-password-never-real';

    /**
     * A separate, shorter fake for the stack-trace test.
     *
     * PHP renders a string argument in a trace truncated to 15 characters, so a
     * needle longer than that could never appear in full and the negative
     * assertion would pass whether or not #[SensitiveParameter] was doing
     * anything. Fourteen characters is short enough to be rendered whole, which
     * is what makes its absence evidence.
     */
    private const string TRACE_CANARY = 'pw-canary-1234';

    /**
     * The two public methods that are allowed to contain the password, and the
     * order sort() puts them in.
     *
     * @return list<string>
     */
    private function permittedExits(): array
    {
        return ['merchantFields', 'userFields'];
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function blankCredentials(): array
    {
        return [
            'empty ClientID' => ['', self::USERNAME, self::PASSWORD, 'ClientID'],
            'whitespace-only ClientID' => ["  \t ", self::USERNAME, self::PASSWORD, 'ClientID'],
            'empty Username' => [self::CLIENT_ID, '', self::PASSWORD, 'Username'],
            'whitespace-only Username' => [self::CLIENT_ID, "  \t ", self::PASSWORD, 'Username'],
            'empty Password' => [self::CLIENT_ID, self::USERNAME, '', 'Password'],
            'whitespace-only Password' => [self::CLIENT_ID, self::USERNAME, "  \t ", 'Password'],
        ];
    }

    /**
     * No public method hands out the password on its own.
     *
     * The interesting half is not the name check — a getter called
     * `credential()` would pass that — but the enumeration: every public method
     * that can be called with no arguments is called, its return value is
     * rendered, and the set of methods whose rendering contains the password is
     * compared against the two the class permits. Adding any accessor that
     * returns the value, under any name, grows that set.
     *
     * Rendering alone had a hole, and it was opened by the very property that
     * closes the var_export() channel: var_export() of a
     * SensitiveParameterValue prints nothing, so an accessor returning the
     * wrapper rather than the string was invisible here.
     * `public function secret(): SensitiveParameterValue { return
     * $this->password; }` passed this test while handing any caller the
     * plaintext through `$credentials->secret()->getValue()`, and returning the
     * wrapper is the natural shape for anyone adding an accessor to a class
     * whose property is one. So the rendering unwraps first, recursively — see
     * unwrapped() — and the return type of every public method is checked
     * against the wrapper as well, this second one covering the methods that
     * take arguments and are therefore never invoked here.
     *
     * Methods with required parameters are skipped by the invocation half,
     * which is exactly __construct and __unserialize. Neither can leak a value
     * it was not given: __construct receives the password rather than returning
     * it, and __unserialize is pinned by its own test below.
     *
     * The private unwrappedPassword() helper does return the raw value and is
     * deliberately not reached here. IS_PUBLIC is the filter because public is
     * the surface; a private helper is how the two permitted exits are
     * implemented, not a third exit.
     */
    public function testNoPublicMethodHandsOutThePasswordExceptTheTwoFieldArrays(): void
    {
        $reflection = new ReflectionClass(Credentials::class);

        self::assertFalse(
            $reflection->hasMethod('password'),
            'There is no password() accessor and there must never be one.',
        );

        $credentials = $this->credentials();
        $leaking = [];
        $handingOutTheWrapper = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array(SensitiveParameterValue::class, $this->typeNames($method->getReturnType()), true)) {
                $handingOutTheWrapper[] = $method->getName();
            }

            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            $rendered = var_export($this->unwrapped($method->invoke($credentials)), true);

            if (str_contains($rendered, self::PASSWORD)) {
                $leaking[] = $method->getName();
            }
        }

        sort($leaking);
        sort($handingOutTheWrapper);

        self::assertSame(
            $this->permittedExits(),
            $leaking,
            'The password may leave the object only inside one of the two request-field arrays.',
        );
        self::assertSame(
            [],
            $handingOutTheWrapper,
            'A method returning the SensitiveParameterValue hands out the password via getValue().',
        );
    }

    /**
     * The public surface is exactly these eight names. A ninth is a failure.
     *
     * The enumeration above asks a question about behaviour — "does any public
     * method render the password" — and is therefore only ever as good as the
     * rendering. Two shapes get past it, and both were run to confirm it.
     * `secretFor(string $purpose): string` returning the password is never
     * invoked, because the enumeration skips anything with required parameters.
     * A method returning an object that holds the SensitiveParameterValue
     * renders as nothing, because rendering as nothing is exactly what the
     * wrapper is there to do.
     *
     * This assertion asks a different question — "what is on the surface" — and
     * both shapes answer it by having a name. So do __toString(),
     * jsonSerialize(), a password() getter and every shape not yet imagined,
     * because a public method cannot exist without one. Nothing here has to
     * anticipate the leak; it only has to notice the surface changed.
     *
     * The surface is frozen deliberately. Credentials holds the only secret in
     * this package, and its class docblock states that merchantFields() and
     * userFields() are the only exits — a class making that claim has no
     * business growing a public method by accident. The maintenance cost is one
     * line in the list below, paid only when someone widens the surface on
     * purpose, which is precisely the moment a reviewer should be reading.
     *
     * Deliberately narrower than teaching unwrapped() to walk arbitrary
     * objects, which would be a guard written for shapes this class does not
     * have. This is a guard against it acquiring them.
     */
    public function testThePublicSurfaceIsFrozenToExactlyTheseMethods(): void
    {
        $names = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Credentials::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($names);

        self::assertSame(
            [
                '__construct',
                '__debugInfo',
                '__serialize',
                '__unserialize',
                'clientId',
                'merchantFields',
                'userFields',
                'username',
            ],
            $names,
            'The public surface of Credentials is frozen. merchantFields() and userFields() are '
            . 'the only ways the password may leave the object, and every shape that could hand '
            . 'it out another way — an accessor taking an argument, one returning the wrapper, a '
            . '__toString(), a jsonSerialize() — announces itself by adding a name to this list. '
            . 'Widening it is a deliberate act. If you did not intend one, you have opened a leak '
            . 'channel; if you did, say why in the docblock of whatever you added.',
        );
    }

    /**
     * The three keys of a request that identifies the merchant, in wire
     * spelling.
     *
     * Asserted with assertSame against a whole array literal rather than key
     * by key. PHP's === on arrays requires the same keys, in the same order,
     * with values of the same type, so one assertion rejects a renamed key, an
     * added key, a dropped key and a swapped value. The spellings are the
     * gateway's, not this package's, and CONVENTIONS.md §4.8 forbids tidying
     * them.
     */
    public function testMerchantFieldsCarriesExactlyTheThreeWireSpellings(): void
    {
        self::assertSame(
            [
                'ClientID' => self::CLIENT_ID,
                'Username' => self::USERNAME,
                'Password' => self::PASSWORD,
            ],
            $this->credentials()->merchantFields(),
        );
    }

    /**
     * The two keys of a request that carries no ClientID —
     * GetPaymentDetails, ConfirmPayment, CancelPayment, RefundPayment.
     *
     * ClientID is absent on purpose, and the whole-array assertion is what
     * keeps it absent: adding it back would be an unknown field, which the
     * gateway ignores silently (CONVENTIONS.md §4.12), so nothing downstream
     * would ever report the mistake.
     */
    public function testUserFieldsCarriesExactlyTheTwoWireSpellings(): void
    {
        self::assertSame(
            [
                'Username' => self::USERNAME,
                'Password' => self::PASSWORD,
            ],
            $this->credentials()->userFields(),
        );
    }

    public function testTheNonSecretFieldsAreReadableOnTheirOwn(): void
    {
        $credentials = $this->credentials();

        self::assertSame(self::CLIENT_ID, $credentials->clientId());
        self::assertSame(self::USERNAME, $credentials->username());
    }

    /**
     * var_dump() prints the marker where the password would be, and the whole
     * rendering is pinned.
     *
     * Asserting only that the marker is present and the password is not was the
     * first version of this test, and infection killed it: dropping the
     * clientId entry from __debugInfo() altogether left the suite green, twice
     * over — once by removing the array item and once by turning `=>` into `>`,
     * which silently replaces the key with 0 and the value with a boolean. Both
     * mutants produce an object whose debug output has quietly stopped
     * describing the object, which is the failure mode a redaction hook is most
     * likely to acquire: someone removes a field to be safe, and nothing says
     * the tool that developers actually use to inspect this object now lies
     * about it.
     *
     * The three-line body is therefore asserted verbatim, with var_dump's
     * per-process object handle normalised away as the one legitimately
     * varying part.
     *
     * The negative assertion that follows is subsumed by the literal — the
     * literal contains no password, so a leak fails the comparison first — and
     * is kept anyway, because "Failed asserting that a string does not contain
     * the password" is the diagnosis a reader of the failure needs, and a
     * whole-buffer diff is not.
     */
    public function testVarDumpPrintsTheMarkerAndNotThePassword(): void
    {
        $dump = $this->dumpWithoutObjectHandle($this->credentials());

        self::assertSame(
            'object(DavitVardanyan\AmeriabankVpos\Config\Credentials)#N (3) {' . "\n"
            . '  ["clientId"]=>' . "\n"
            . '  string(14) "fake-client-id"' . "\n"
            . '  ["username"]=>' . "\n"
            . '  string(13) "fake-username"' . "\n"
            . '  ["password"]=>' . "\n"
            . '  string(10) "[redacted]"' . "\n"
            . '}' . "\n",
            $dump,
        );
        self::assertStringNotContainsString(self::PASSWORD, $dump);
    }

    /**
     * print_r() prints the marker too, and it is the same hook doing it.
     *
     * The class docblock has claimed this channel is closed "also via
     * __debugInfo()" since it was written, and until now nothing under tests/
     * mentioned print_r at all. The claim rested on the reader trusting that
     * PHP routes print_r through the same debug hook as var_dump, which the
     * manual does not document. That is the category of unexecuted claim that
     * got json_encode() and the (string) cast pinned, and this is the widest of
     * the three in practice: print_r is what gets interpolated into a log line
     * or a debug response body.
     *
     * The rendering is pinned whole rather than searched for the marker, for
     * the reason recorded on the var_dump test above: a marker-only assertion
     * stays green for a __debugInfo() that has quietly stopped describing the
     * object. print_r carries no object handle, so unlike var_dump the literal
     * needs no normalisation.
     *
     * The literal is also what proves the hook ran. With no __debugInfo() PHP
     * renders a private property under its fully qualified owner, as
     * `[password:DavitVardanyan\AmeriabankVpos\Config\Credentials:private]`,
     * and prints its contents. Deleting the hook was run and did exactly that;
     * so did a control class that never had one, on PHP 8.3.28, 8.4.15 and
     * 8.5.7, printing a bare-string password in full. The unsuffixed
     * `[password]` key below is therefore what a consulted __debugInfo() looks
     * like, and this assertion fails both if the hook stops redacting and if
     * print_r ever stops consulting it.
     *
     * Deliberately redundant with the var_dump test — deleting __debugInfo()
     * fails both. Redundancy is the point: these are two separate language
     * features, and this file does not want to learn by incident that they
     * diverged.
     */
    public function testPrintRPrintsTheMarkerAndNotThePassword(): void
    {
        $rendered = print_r($this->credentials(), true);

        self::assertSame(
            Credentials::class . ' Object' . "\n"
            . '(' . "\n"
            . '    [clientId] => ' . self::CLIENT_ID . "\n"
            . '    [username] => ' . self::USERNAME . "\n"
            . '    [password] => [redacted]' . "\n"
            . ')' . "\n",
            $rendered,
        );
        self::assertStringNotContainsString(self::PASSWORD, $rendered);
    }

    /**
     * serialize() is pinned whole.
     *
     * A serialized Credentials is a password at rest in a session store, a
     * cache file or a queue payload. Asserting the entire payload — including
     * `s:10:"[redacted]"`, the marker with its own byte count — means any
     * change to what __serialize() emits has to come through this literal,
     * whether it adds a field, renames one, or replaces the marker.
     */
    public function testSerializePutsTheMarkerWhereThePasswordWouldBe(): void
    {
        $serialized = serialize($this->credentials());

        self::assertSame(
            'O:48:"DavitVardanyan\AmeriabankVpos\Config\Credentials":3:{'
            . 's:8:"clientId";s:14:"fake-client-id";'
            . 's:8:"username";s:13:"fake-username";'
            . 's:8:"password";s:10:"[redacted]";}',
            $serialized,
        );
        self::assertStringNotContainsString(self::PASSWORD, $serialized);
    }

    /**
     * The redacted rendering is the same for two different secrets.
     *
     * This is the assertion that forbids a marker carrying the password's
     * length, and it forbids a hash, a prefix and a character count on the same
     * terms: any marker derived from the secret differs between a one-character
     * password and a thirty-character one, and this comparison fails the moment
     * it does. Asserting the presence of `[redacted]` cannot do that job on
     * purpose — it only manages it by the accident of a closing bracket.
     *
     * The two objects differ in nothing but the password, so var_dump's object
     * handle is the one legitimate difference between the renderings and it is
     * normalised away. serialize() carries no handle and is compared as-is.
     */
    public function testTheRedactedRenderingDoesNotVaryWithTheSecret(): void
    {
        $shortSecret = new Credentials(self::CLIENT_ID, self::USERNAME, 'x');
        $longSecret = new Credentials(self::CLIENT_ID, self::USERNAME, 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');

        self::assertSame(
            $this->dumpWithoutObjectHandle($shortSecret),
            $this->dumpWithoutObjectHandle($longSecret),
            'A rendering that varies with the secret is a disclosure of the secret, in small pieces.',
        );
        self::assertSame(serialize($shortSecret), serialize($longSecret));
    }

    /**
     * var_export() renders the wrapper, and the wrapper renders nothing.
     *
     * This is the leak channel with no hook: var_export() consults neither
     * __debugInfo() nor __serialize(), it walks the real properties. The class
     * docblock says holding the password in a SensitiveParameterValue is what
     * closes it, and nothing held that claim until this test. Swapping the
     * property to a bare string — and collapsing unwrappedPassword() to
     * `return $this->password;`, since the helper and its assert() exist only
     * to narrow SensitiveParameterValue::getValue() from mixed — printed the
     * password verbatim inside __set_state() and left the whole suite green.
     * That is a tidying a reader who doubts the docblock would plausibly make,
     * and infection will never propose it: it mutates expressions, not class
     * design.
     *
     * The first three assertions are the positive control. A negative
     * assertion on its own is green for an export that rendered nothing, so
     * the clientId and username values are asserted present: the export really
     * did walk this object's properties and print their contents, which is
     * what makes the password's absence evidence rather than silence.
     */
    public function testVarExportRendersTheWrapperAndNotThePassword(): void
    {
        $export = var_export($this->credentials(), true);

        self::assertStringContainsString(
            Credentials::class . '::__set_state(array(',
            $export,
            'The export must have rendered the object, or the assertions below prove nothing.',
        );
        self::assertStringContainsString("'clientId' => '" . self::CLIENT_ID . "'", $export);
        self::assertStringContainsString("'username' => '" . self::USERNAME . "'", $export);
        self::assertStringContainsString(
            SensitiveParameterValue::class . '::__set_state(array(',
            $export,
            'The password must be held in a wrapper: it is the only thing closing this channel.',
        );
        self::assertStringNotContainsString(self::PASSWORD, $export);
    }

    /**
     * json_encode() has nothing to encode.
     *
     * The class docblock records this channel as printing {}, which is true
     * structurally rather than by any control this class exercises: the
     * properties are private and the class does not implement JsonSerializable.
     * Implementing it later — to make a config object loggable, say — would
     * falsify that docblock line and open the channel in the same commit, with
     * nothing to say so. Pinning the exact output is what makes such a commit
     * fail here.
     */
    public function testJsonEncodingCredentialsProducesAnEmptyObject(): void
    {
        self::assertSame('{}', json_encode($this->credentials()));
    }

    /**
     * A (string) cast has no channel to close, because the class declares no
     * __toString().
     *
     * Structural in the same way, and one
     * `public function __toString(): string { return $this->unwrappedPassword(); }`
     * away from being false — after which every interpolation of this object
     * into a log line, an exception message or a URL would print the password.
     *
     * The conversion is executed through strval() rather than a literal
     * (string) cast because PHPStan level 10 rejects the cast outright:
     * "Cannot cast Credentials to string". That is a static restatement of the
     * same fact, not a substitute for observing it, and this project's standing
     * discipline is that a comment's claim is executed or deleted. Reflection
     * hides the argument from the analyser; the engine performs the identical
     * conversion and raises the identical Error, verified on PHP 8.3.28 to be
     * byte-identical in class and message to the one `(string) $credentials`
     * raises.
     */
    public function testCastingCredentialsToStringThrowsRatherThanRenderingIt(): void
    {
        self::assertFalse(
            (new ReflectionClass(Credentials::class))->hasMethod('__toString'),
            'A __toString() would make every interpolation of this object a leak.',
        );

        $this->expectException(Error::class);
        $this->expectExceptionMessage(
            'Object of class ' . Credentials::class . ' could not be converted to string',
        );

        (new ReflectionFunction('strval'))->invoke($this->credentials());
    }

    /**
     * A round trip through serialize()/unserialize() refuses to complete.
     *
     * __serialize() redacts, so a restored object would hold the marker where
     * the password belongs and would fail authentication with ResponseCode 20 —
     * from the caller's side indistinguishable from a merchant who typed the
     * wrong password. The message is pinned whole because the message is the
     * entire value of throwing here: it is what turns a support ticket about
     * correct-looking credentials into a one-line fix.
     */
    public function testUnserializingCredentialsRefusesToRestoreThem(): void
    {
        $serialized = serialize($this->credentials());

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Credentials cannot be unserialized. Serializing redacts the password, '
            . 'so a restored object would carry a marker where a secret belongs and '
            . 'would fail authentication as if the credentials were wrong. '
            . 'Construct Credentials from your configuration instead.',
        );

        unserialize($serialized);
    }

    /**
     * Six rows, not one loop over three fields crossed with two spellings of
     * blank.
     *
     * A loop with a single assertion stops at its first failure, so a
     * constructor that stopped checking Username would be indistinguishable
     * from one that stopped checking Username and Password. Six rows are six
     * results. The message is asserted, not merely the exception class: all six
     * throw the same class, and only the text says which field the constructor
     * actually rejected.
     *
     * The whitespace rows are the ones that pin trim(). A password of spaces is
     * a configuration mistake, not a password, and the gateway answers it with
     * ResponseCode 20 and no indication of which field was wrong.
     */
    #[DataProvider('blankCredentials')]
    public function testABlankCredentialFieldIsRejectedByName(
        string $clientId,
        string $username,
        string $password,
        string $expectedField,
    ): void {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(sprintf('Credential field "%s" must not be blank.', $expectedField));

        new Credentials($clientId, $username, $password);
    }

    /**
     * Nothing is assigned until everything has been validated.
     *
     * The class docblock claims this, and the properties are declared rather
     * than promoted solely to make it true — promotion assigns on entry, before
     * a constructor body could reject anything. A claim in a docblock that no
     * test executes is a claim nobody is holding, and the memory note from an
     * earlier task is explicit: execute a comment's claim or delete it.
     *
     * From outside the class the difference is invisible, because a constructor
     * that throws leaves no object to inspect. Reflection supplies one:
     * newInstanceWithoutConstructor() creates the instance with every typed
     * property uninitialized, and invoking the constructor on it afterwards
     * runs the real body in the real class scope. If validation moved below the
     * assignments, clientId and username would be initialized by the time the
     * Password check threw, and isInitialized() reports it.
     *
     * Password is the field left blank on purpose: it is checked last, so it is
     * the only case where a misordered constructor would have already written
     * the two fields in front of it.
     */
    public function testNoFieldIsAssignedBeforeEveryFieldHasBeenValidated(): void
    {
        $reflection = new ReflectionClass(Credentials::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor, 'Credentials declares a constructor.');

        $instance = $reflection->newInstanceWithoutConstructor();
        $thrown = null;

        try {
            $constructor->invoke($instance, self::CLIENT_ID, self::USERNAME, '   ');
        } catch (ConfigurationException $exception) {
            $thrown = $exception;
        }

        self::assertNotNull($thrown, 'A whitespace-only Password must be rejected.');
        self::assertSame('Credential field "Password" must not be blank.', $thrown->getMessage());

        foreach (['clientId', 'username', 'password'] as $property) {
            self::assertFalse(
                $reflection->getProperty($property)->isInitialized($instance),
                sprintf('%s must not have been assigned before the constructor rejected the input.', $property),
            );
        }
    }

    /**
     * A stack trace from inside the constructor does not carry the password.
     *
     * #[SensitiveParameter] is what closes this channel: it makes the engine
     * render the argument as Object(SensitiveParameterValue) in every frame
     * instead of quoting it. Traces reach logs, error trackers and, on a
     * misconfigured host, the response body, so this is the widest of the leak
     * channels rather than the narrowest.
     *
     * zend.exception_ignore_args is forced off for the duration. It is
     * PHP_INI_ALL, php.ini-production ships it On, and with it On no argument
     * is rendered in a trace at all — which would make the negative assertion
     * below pass on a machine where #[SensitiveParameter] had been deleted.
     * A guard whose result depends on the INI of the runner is not a guard, so
     * the test sets the hostile value itself and restores whatever was there.
     *
     * The username assertion is the other half: it proves arguments really are
     * being rendered in this trace, so the password's absence from it means the
     * attribute worked rather than that there was nothing to find.
     */
    public function testAConstructorStackTraceDoesNotCarryThePassword(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $thrown = null;

        try {
            new Credentials('', self::USERNAME, self::TRACE_CANARY);
        } catch (Throwable $exception) {
            $thrown = $exception;
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }

        self::assertNotNull($thrown, 'A blank ClientID must be rejected.');

        $trace = $thrown->getTraceAsString();

        self::assertStringContainsString('__construct', $trace, 'The constructor frame must be in the trace.');
        self::assertStringContainsString(
            "'" . self::USERNAME . "'",
            $trace,
            'Arguments must be rendered in this trace, or the assertion below proves nothing.',
        );
        self::assertStringNotContainsString(self::TRACE_CANARY, $trace);
        self::assertStringNotContainsString(
            self::TRACE_CANARY,
            (string) $thrown,
            'Throwable::__toString() embeds the same trace and is what an uncaught exception prints.',
        );
    }

    /**
     * Every class or builtin name a declared type mentions.
     *
     * Casting a ReflectionType to string would be one line, and PHP 8.5
     * deprecates it. Walking the type is also the more honest read: a union
     * `SensitiveParameterValue|string` hands out the wrapper on some path, and
     * a substring match on the rendered type would be answering a different
     * question than "does this signature mention the wrapper".
     *
     * @return list<string>
     */
    private function typeNames(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];

            foreach ($type->getTypes() as $member) {
                $names = [...$names, ...$this->typeNames($member)];
            }

            return $names;
        }

        return [];
    }

    /**
     * The same value with every SensitiveParameterValue replaced by what it
     * holds, recursively through arrays.
     *
     * var_export() of a SensitiveParameterValue renders
     * `\SensitiveParameterValue::__set_state(array())` — nothing at all — so
     * the enumeration above is blind to an accessor that hands back the wrapper
     * instead of the string, and to a field array carrying one. To the caller
     * those are plaintext either way, one ->getValue() apart. The property that
     * closes the var_export() channel is the same property that would blind the
     * guard, which is why the guard unwraps before it renders.
     *
     * Arrays are walked because the two permitted exits are arrays and a third
     * exit would be one too. Other objects are not walked, and that is exactly
     * where this guard stops — but one step further along than it first looks.
     * var_export() walks private properties as well as public ones, so an
     * accessor returning some PasswordHolder is caught here whenever the holder
     * stores the password as a string, whatever its visibility. Both shapes
     * were run against this enumeration. A holder declaring
     * `private string $value` came out red; a holder declaring
     * `private SensitiveParameterValue $value` survived, because the nested
     * wrapper renders as nothing and the declared return type is the holder
     * rather than the wrapper, so neither half of the test sees it. An object
     * holding the wrapper is therefore the entire residual gap — not an object
     * holding the password, and not a matter of the property being public.
     *
     * That gap is closed by the frozen-surface test above, which fails on the
     * holder's method name before any rendering happens. The two guards are
     * redundant on purpose: this one catches a leak by its behaviour, that one
     * by the surface changing at all, and the shapes each is blind to are the
     * shapes the other is built for.
     *
     * Teaching this helper to walk arbitrary objects instead would be a guard
     * written for shapes this class does not have, which is the decorative kind
     * this file exists to avoid.
     */
    private function unwrapped(mixed $value): mixed
    {
        if ($value instanceof SensitiveParameterValue) {
            return $this->unwrapped($value->getValue());
        }

        if (is_array($value)) {
            return array_map($this->unwrapped(...), $value);
        }

        return $value;
    }

    private function credentials(): Credentials
    {
        return new Credentials(self::CLIENT_ID, self::USERNAME, self::PASSWORD);
    }

    private function dumpOf(Credentials $credentials): string
    {
        ob_start();
        var_dump($credentials);
        $dump = ob_get_clean();

        self::assertIsString($dump, 'The output buffer must have been active.');

        return $dump;
    }

    /**
     * The same dump with var_dump's object handle normalised away.
     *
     * The handle is a per-process counter, so two instances necessarily differ
     * in it, and it says nothing about either object's contents.
     */
    private function dumpWithoutObjectHandle(Credentials $credentials): string
    {
        $normalised = preg_replace('/#\d+ /', '#N ', $this->dumpOf($credentials));

        self::assertIsString($normalised, 'The pattern is a literal and cannot fail to compile.');

        return $normalised;
    }
}
