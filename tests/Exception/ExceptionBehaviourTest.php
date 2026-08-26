<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Exception;

use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\AuthenticationException;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\GatewayFaultException;
use DavitVardanyan\AmeriabankVpos\Exception\IndeterminateStateException;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Exception\TransportException;
use DavitVardanyan\AmeriabankVpos\Exception\ValidationException;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(ApiException::class)]
#[CoversClass(AuthenticationException::class)]
#[CoversClass(ConfigurationException::class)]
#[CoversClass(GatewayFaultException::class)]
#[CoversClass(IndeterminateStateException::class)]
#[CoversClass(SerializationException::class)]
#[CoversClass(TransportException::class)]
#[CoversClass(ValidationException::class)]
final class ExceptionBehaviourTest extends TestCase
{
    /**
     * Pinned whole, because substring checks did not pin the bounds.
     *
     * Changing the message to "between 0 and 1200" left the suite green, even
     * though it contradicts the 1..1200 range in CONVENTIONS.md §4.12 that the
     * class docblock itself cites. The lower bound is load-bearing: the
     * gateway accepts 0 and -1 silently, so this range is the only thing
     * enforcing it.
     */
    public function testTimeoutMessageNamesTheBoundsAndTheOffendingValue(): void
    {
        self::assertSame(
            'Timeout must be between 1 and 1200 seconds, got 1201. The gateway '
            . 'accepts out-of-range values silently, so this is enforced here.',
            ValidationException::timeoutOutOfRange(1201)->getMessage(),
        );
    }

    /**
     * Pinned whole, because every fragment the old test asserted — '7',
     * 'GetBindings', '5, 6' — is invariant under reordering the concatenated
     * halves of the format string, and invariant under dropping the second half
     * altogether. Both mutations survived.
     *
     * The trailing half is not decoration: it carries the CONVENTIONS.md §4.2
     * rationale for why this is validated client-side at all. An out-of-range
     * PaymentType returns an unparseable HTTP 500, so a caller who sees only
     * "Allowed: 5, 6" has no way to know that ignoring this check yields a
     * useless error rather than a structured one.
     */
    public function testUnsupportedPaymentTypeListsWhatIsAllowedAndWhy(): void
    {
        self::assertSame(
            'PaymentType 7 is not accepted by GetBindings. Allowed: 5, 6. Other values '
            . 'return an unparseable HTTP 500 from the gateway.',
            ValidationException::unsupportedPaymentType(7, 'GetBindings', [5, 6])->getMessage(),
        );
    }

    /**
     * -4200, not 0: the old needle '0' also occurs in the constant text, so
     * replacing the factory body with a hardcoded string that discarded its
     * argument entirely left the suite green. A negative value is also the
     * realistic input, since the factory exists to reject non-positive amounts.
     */
    public function testAmountNotPositiveReportsTheValue(): void
    {
        self::assertSame(
            'Amount must be greater than zero, got -4200 minor units.',
            ValidationException::amountNotPositive(-4200)->getMessage(),
        );
    }

    public function testBlankValueNamesTheFieldOnly(): void
    {
        self::assertStringContainsString('OrderID', ValidationException::blankValue('OrderID')->getMessage());
    }

    /**
     * Pinned whole: asserting both substrings could not detect the two sprintf
     * arguments being swapped, which yields the confident nonsense
     * 'Field "a GUID" is malformed: expected PaymentID.'
     */
    public function testMalformedValueStatesTheExpectation(): void
    {
        self::assertSame(
            'Field "PaymentID" is malformed: expected a GUID.',
            ValidationException::malformedValue('PaymentID', 'a GUID')->getMessage(),
        );
    }

    /**
     * Asserted as an exact message, not as "the secret is absent".
     *
     * The previous form checked that a literal password string did not appear in
     * the message, but that string was never an input to anything, so the
     * assertion was vacuous: adding a parameter that interpolated the credential
     * value straight into the message left the suite green. Pinning the whole
     * message means any future interpolation fails here, and no secret needs to
     * be written down to test for its absence.
     */
    public function testBlankCredentialNamesTheFieldNotTheValue(): void
    {
        self::assertSame(
            'Credential field "Password" must not be blank.',
            ConfigurationException::blankCredential('Password')->getMessage(),
        );
    }

    /**
     * Pinned whole, because the message is the entire point of the factory.
     *
     * Credentials::__unserialize() throws this instead of restoring, and what
     * it restores would otherwise be a redaction marker sitting where a
     * password belongs — which the gateway answers with ResponseCode 20,
     * indistinguishable from a merchant who typed the wrong password
     * (CONVENTIONS.md §4.3). A caller who reads only "Credentials cannot be
     * unserialized" learns that something is refused; the two sentences after
     * it are what tell them the object they are restoring was never carrying a
     * secret in the first place, and what to do instead. Asserting a fragment
     * would leave both free to be dropped.
     *
     * No password appears here, or anywhere in this factory: the message names
     * the mechanism, never a value (CONVENTIONS.md §6).
     */
    public function testCredentialsNotUnserializableExplainsTheRedactionAndTheFix(): void
    {
        self::assertSame(
            'Credentials cannot be unserialized. Serializing redacts the password, '
            . 'so a restored object would carry a marker where a secret belongs and '
            . 'would fail authentication as if the credentials were wrong. '
            . 'Construct Credentials from your configuration instead.',
            ConfigurationException::credentialsNotUnserializable()->getMessage(),
        );
    }

    /**
     * Pinned whole, and in both wire types.
     *
     * Building an exception from a success code is a call-site error, so the
     * message has to name which code and which operation. Both sprintf
     * arguments are the same shape once interpolated, which leaves them free to
     * swap into the confident nonsense "Response code GetPaymentDetails from 00
     * is a success code"; asserting a fragment such as "success code" leaves the
     * whole message free to be replaced by a constant that names neither.
     *
     * The "00" row is the one that pins the int|string parameter against being
     * narrowed to int. Executed on PHP 8.3.28: (string) (int) '00' is '0', so
     * a narrowed signature would render this message as "Response code 0",
     * which is a different wire value (CONVENTIONS.md §4.3). The int row is
     * the counterweight — it is what stops the leading zero being restored by
     * a blanket left-pad.
     *
     * ResponseCode::toException() is the only caller, and
     * tests/Response/ResponseCodeTest.php pins the message through that path
     * too. This asserts the factory directly, because that test only reaches
     * one of the two types.
     */
    public function testSuccessCodeRefusalNamesTheCodeAndTheOperation(): void
    {
        self::assertSame(
            'Response code 00 from GetPaymentDetails is a success code; an exception cannot be built from it.',
            ConfigurationException::successCodeHasNoException('GetPaymentDetails', '00')->getMessage(),
        );
        self::assertSame(
            'Response code 1 from InitPayment is a success code; an exception cannot be built from it.',
            ConfigurationException::successCodeHasNoException('InitPayment', 1)->getMessage(),
        );
    }

    /**
     * Each factory is pinned whole, because the three were interchangeable.
     *
     * The old test asserted only that 'psr7' appeared in each message — a
     * needle all three share — so giving noRequestFactoryFound() the
     * stream-factory message verbatim stayed green. Under CONVENTIONS.md §5
     * ConfigurationException is always a programming error, and its entire
     * value is telling the developer which of the three to install.
     */
    public function testDiscoveryFailuresSuggestAnInstallableImplementation(): void
    {
        $previous = new RuntimeException('nothing found');

        self::assertSame(
            'No PSR-18 HTTP client could be discovered. Install one '
            . '(for example guzzlehttp/guzzle or symfony/http-client), '
            . 'or pass an implementation explicitly.',
            ConfigurationException::noHttpClientFound($previous)->getMessage(),
        );
        self::assertSame(
            'No PSR-17 request factory could be discovered. Install a PSR-7 '
            . 'implementation (for example nyholm/psr7), or pass factories '
            . 'explicitly.',
            ConfigurationException::noRequestFactoryFound($previous)->getMessage(),
        );
        self::assertSame(
            'No PSR-17 stream factory could be discovered. Install a PSR-7 '
            . 'implementation (for example nyholm/psr7), or pass factories '
            . 'explicitly.',
            ConfigurationException::noStreamFactoryFound($previous)->getMessage(),
        );

        // The three must stay mutually distinguishable, not merely correct today.
        self::assertNotSame(
            ConfigurationException::noRequestFactoryFound($previous)->getMessage(),
            ConfigurationException::noStreamFactoryFound($previous)->getMessage(),
        );
    }

    /**
     * The message names the cause's class; it must never carry the cause's own
     * message. Guzzle's RequestException::getMessage() embeds a response-body
     * excerpt and the full request URL, so interpolating it would route a raw
     * body — possibly carrying card data — into every log. See CONVENTIONS.md
     * §6.
     *
     * The negative assertion is the one that matters: it fails loudly if the
     * interpolation is ever reverted. The cause itself stays reachable through
     * getPrevious(), which is the stronger guarantee anyway.
     */
    public function testTransportExceptionKeepsOperationAndCause(): void
    {
        $previous = new RuntimeException('connection reset');
        $exception = TransportException::requestFailed('InitPayment', $previous::class, $previous);

        self::assertSame('InitPayment', $exception->operation());
        self::assertSame($previous, $exception->getPrevious());
        self::assertStringContainsString(RuntimeException::class, $exception->getMessage());
        self::assertStringNotContainsString('connection reset', $exception->getMessage());
    }

    /**
     * Pinned whole. 'Do not retry', 'GetPaymentDetails' and the payment ID all
     * survive the two halves of the format string being swapped, which produces
     * a message that opens with the instruction and buries the operation name
     * after it — and, worse, misbinds the %s placeholders, so the operation is
     * rendered where the payment clause belongs.
     *
     * This is the one message in the package whose exact reading decides whether
     * a caller double-captures or double-refunds, so it is the last one that
     * should be asserted by fragments that cannot tell it from its scramble.
     */
    public function testIndeterminateStateTellsTheCallerToReconcileAndNotRetry(): void
    {
        $exception = IndeterminateStateException::afterTransportFailure(
            'RefundPayment',
            'C2E51643-0922-4442-A80C-30ADAE03BECC',
            new RuntimeException('timeout'),
        );

        self::assertSame(
            'The RefundPayment request failed in transport and its outcome is unknown. '
            . 'Do not retry. Reconcile with GetPaymentDetails for payment '
            . 'C2E51643-0922-4442-A80C-30ADAE03BECC before acting.',
            $exception->getMessage(),
        );
        self::assertSame('RefundPayment', $exception->operation());
        self::assertSame('C2E51643-0922-4442-A80C-30ADAE03BECC', $exception->paymentId());
    }

    /**
     * The null branch is pinned whole for the same reason, and additionally
     * because 'Reconcile with GetPaymentDetails before acting.' must still read
     * as a sentence once the optional clause collapses to the empty string.
     */
    public function testIndeterminateStateOmitsThePaymentClauseWhenUnknown(): void
    {
        $exception = IndeterminateStateException::afterTransportFailure(
            'CancelPayment',
            null,
            new RuntimeException('timeout'),
        );

        self::assertSame(
            'The CancelPayment request failed in transport and its outcome is unknown. '
            . 'Do not retry. Reconcile with GetPaymentDetails before acting.',
            $exception->getMessage(),
        );
        self::assertNull($exception->paymentId());
        self::assertStringNotContainsString('for payment', $exception->getMessage());
    }

    public function testApiExceptionExposesOperationCodeAndMessage(): void
    {
        $exception = ApiException::fromResponse('ConfirmPayment', '05', 'Incorrect Parameters');

        self::assertSame('ConfirmPayment', $exception->operation());
        self::assertSame('05', $exception->responseCode());
        self::assertSame('Incorrect Parameters', $exception->responseMessage());
        self::assertStringContainsString('ConfirmPayment', $exception->getMessage());
        self::assertStringContainsString('05', $exception->getMessage());
        self::assertStringContainsString('Incorrect Parameters', $exception->getMessage());
    }

    public function testApiExceptionHandlesAnEmptyResponseMessage(): void
    {
        $exception = ApiException::fromResponse('GetBindings', '500', '');

        self::assertSame('', $exception->responseMessage());
        self::assertStringContainsString('(no message)', $exception->getMessage());
    }

    /**
     * fromResponse() must construct with new static(), not new self(), or every
     * leaf subclass would collapse to ApiException at the call site.
     *
     * The factory result is returned through a Throwable-typed helper so that
     * both assertions below stay real narrowing steps: Throwable to ApiException,
     * then ApiException to AuthenticationException. Asserting the leaf first
     * would make the ApiException assertion a tautology, and a widening @var tag
     * cannot express this because PHPStan rejects a @var wider than the native
     * type. Both assertions must stay.
     */
    public function testSubclassFactoryReturnsTheSubclass(): void
    {
        $exception = $this->authenticationFailure();

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertInstanceOf(AuthenticationException::class, $exception);
        self::assertSame(20, $exception->responseCode());
    }

    /**
     * Declared Throwable, not AuthenticationException, so the caller above has
     * something to narrow. The widening is the point of this helper.
     */
    private function authenticationFailure(): Throwable
    {
        return AuthenticationException::fromResponse('InitPayment', 20, 'Incorrect Username and Password');
    }

    /**
     * Pinned whole, and with the observed values.
     *
     * Fragments do not distinguish this message from its scramble: '500',
     * 'GetPaymentDetails' and the fault text all survive the sprintf arguments
     * being reordered, and the closing sentence — the part that tells a caller
     * this is a refusal rather than a failure worth retrying (CONVENTIONS.md
     * §4.2) — survives being dropped entirely.
     *
     * The inputs are the ones CONVENTIONS.md §4.2 records from the live
     * sandbox: GetPaymentDetails on an order registered but never attempted,
     * answered HTTP 500 with `{"Message":"An error has occurred."}`.
     */
    public function testGatewayFaultCarriesTheOperationTheStatusAndTheMessageText(): void
    {
        $exception = GatewayFaultException::fromFaultEnvelope('GetPaymentDetails', 500, 'An error has occurred.');

        self::assertSame(
            'GetPaymentDetails returned a gateway fault (HTTP 500) carrying no response code, '
            . 'so the request was not answered; do not retry it. '
            . 'The gateway reported: An error has occurred.',
            $exception->getMessage(),
        );
        self::assertSame('GetPaymentDetails', $exception->operation());
        self::assertSame(500, $exception->statusCode());
        self::assertSame('An error has occurred.', $exception->faultMessage());
    }

    /**
     * The envelope shape is observed; its Message being non-empty is not a
     * guarantee the gateway makes, so the empty branch has to read as a
     * sentence too. Pinned whole for the same reason as the populated one.
     *
     * A 404 rather than a 500: the same envelope arrives from both, which is
     * why detection is shape-based and the status is diagnostic only.
     */
    public function testGatewayFaultRendersAnEmptyMessageTextAsNoMessage(): void
    {
        $exception = GatewayFaultException::fromFaultEnvelope('GetBindings', 404, '');

        self::assertSame(
            'GetBindings returned a gateway fault (HTTP 404) carrying no response code, '
            . 'so the request was not answered; do not retry it. '
            . 'The gateway reported: (no message)',
            $exception->getMessage(),
        );
        self::assertSame('', $exception->faultMessage());
        self::assertSame(404, $exception->statusCode());
    }

    /**
     * The factory takes the decoded `Message` value, never the envelope it
     * came from. A body may carry card data and an exception message reaches
     * logs (CONVENTIONS.md §5, §6).
     *
     * This assertion is the behavioural half only. The structural half lives in
     * ExceptionHierarchyTest: adding a $body, $payload, $raw or $content
     * parameter to any public method here fails its credential-and-card-datum
     * guard, which now covers this class through the provider.
     */
    public function testGatewayFaultMessageCarriesTheTextAndNotTheEnvelope(): void
    {
        $envelope = '{"Message":"An error has occurred."}';

        $message = GatewayFaultException::fromFaultEnvelope('GetPaymentDetails', 500, 'An error has occurred.')
            ->getMessage();

        self::assertStringContainsString('An error has occurred.', $message);
        self::assertStringNotContainsString($envelope, $message);
        self::assertStringNotContainsString('{"Message"', $message);
    }

    /**
     * A fault is not a business answer, so it must not be catchable as one.
     *
     * Widened through a Throwable-typed helper so both narrowing steps stay
     * real runtime facts: a literal type here would let the analyser discharge
     * the assertions, and assertNotInstanceOf on a statically known unrelated
     * type is reported as always-true at level 10.
     */
    public function testGatewayFaultIsCaughtByTheMarkerButNotAsAnApiException(): void
    {
        $exception = $this->gatewayFault();

        self::assertInstanceOf(VposExceptionInterface::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertNotInstanceOf(
            ApiException::class,
            $exception,
            'A fault carries no response code; catching it as an ApiException would '
            . 'mean reading a code the gateway never sent.',
        );
    }

    /**
     * Declared Throwable, not GatewayFaultException, so the caller above has
     * something to narrow. The widening is the point of this helper.
     */
    private function gatewayFault(): Throwable
    {
        return GatewayFaultException::fromFaultEnvelope('GetPaymentDetails', 500, 'An error has occurred.');
    }

    /**
     * causedByJson() is now structurally guaranteed true on the malformedJson()
     * path, since that factory takes a JsonException. The assertion is kept
     * anyway: it no longer guards the call site, but it still guards the
     * instanceof in the constructor that derives the flag, which could be
     * narrowed or inverted. The assertFalse on the XML path is unaffected and
     * remains the discriminating half.
     *
     * The flag is derived at construction rather than read from getPrevious() at
     * call time because the chain does not survive serialization; the round trip
     * is pinned in ExceptionSerializationTest.
     */
    public function testSerializationExceptionDistinguishesJsonFromXml(): void
    {
        $json = SerializationException::malformedJson('InitPayment', new JsonException('Syntax error'));
        $xml = SerializationException::malformedXml('GetTransactionList', 'unexpected root element');

        self::assertTrue($json->causedByJson());
        self::assertFalse($xml->causedByJson());
        self::assertSame('InitPayment', $json->operation());
        self::assertStringContainsString('unexpected root element', $xml->getMessage());
    }

    /**
     * The JSON leak path needs the same regression guard as the transport one.
     *
     * malformedJson() and TransportException::requestFailed() received the
     * identical $previous::class fix, but only the transport path was
     * defended, so reverting this one would have passed silently. A decoder's
     * own message can quote the payload it choked on, and the SDK does not
     * control what a third-party decoder or PSR-18 client puts there, so the
     * containment has to live in the factory and be pinned here. See
     * CONVENTIONS.md §6.
     *
     * The sentinel is a made-up marker, never a real value: the needle of a
     * negative assertion is still a literal in a committed file.
     */
    public function testMalformedJsonNamesTheCauseWithoutLeakingItsMessage(): void
    {
        $previous = new JsonException('Syntax error near sentinel-payload-must-not-leak');
        $exception = SerializationException::malformedJson('InitPayment', $previous);

        self::assertSame('InitPayment', $exception->operation());
        self::assertSame($previous, $exception->getPrevious());
        self::assertStringContainsString(JsonException::class, $exception->getMessage());
        self::assertStringNotContainsString(
            'sentinel-payload-must-not-leak',
            $exception->getMessage(),
            'The cause\'s own message must never be interpolated: it can quote the payload.',
        );
    }

    public function testUnexpectedPayloadDescribesTheReason(): void
    {
        $exception = SerializationException::unexpectedPayload('GetBindings', 'CardBindingFileds was not an array');

        self::assertStringContainsString('CardBindingFileds', $exception->getMessage());
        self::assertFalse($exception->causedByJson());
    }

    /**
     * Every exception in the package carries SPL code 0, and nothing else.
     *
     * This is a contract, not an accident of the constructors.
     * Throwable::getCode() is the obvious place a caller will reach for a
     * gateway response code, and CONVENTIONS.md §4.3 makes that reach unsafe:
     * the code is int 1 from InitPayment and string "00" elsewhere, so it does
     * not fit an int at all. The raw value lives on
     * ApiException::responseCode(), which is a union type for exactly that
     * reason. Leaving getCode() pinned at 0 means a caller who reads it gets
     * an unambiguous "this channel carries nothing", rather than a number that
     * looks like a response code on one endpoint and is one nowhere.
     *
     * Every construction path is exercised separately because each writes its own
     * literal: ConfigurationException's three discovery factories are three
     * distinct call sites, not one shared constructor, so a single instance would
     * leave the other two unasserted. The eight factories that pass no code at
     * all are included to pin the same value against the SPL default.
     */
    public function testNoExceptionUsesTheSplCodeChannel(): void
    {
        $previous = new RuntimeException('discovery failed');

        self::assertSame(0, ApiException::fromResponse('ConfirmPayment', '05', 'Incorrect Parameters')->getCode());
        self::assertSame(
            0,
            AuthenticationException::fromResponse('InitPayment', 20, 'Incorrect Username and Password')->getCode(),
            'The subclass shares ApiException\'s final constructor and must not diverge.',
        );

        self::assertSame(0, ConfigurationException::noHttpClientFound($previous)->getCode());
        self::assertSame(0, ConfigurationException::noRequestFactoryFound($previous)->getCode());
        self::assertSame(0, ConfigurationException::noStreamFactoryFound($previous)->getCode());
        self::assertSame(0, ConfigurationException::blankCredential('Password')->getCode());
        self::assertSame(0, ConfigurationException::credentialsNotUnserializable()->getCode());
        self::assertSame(
            0,
            ConfigurationException::successCodeHasNoException('InitPayment', 1)->getCode(),
        );

        self::assertSame(
            0,
            IndeterminateStateException::afterTransportFailure('RefundPayment', null, $previous)->getCode(),
        );
        self::assertSame(0, TransportException::requestFailed('InitPayment', $previous::class, $previous)->getCode());
        self::assertSame(
            0,
            GatewayFaultException::fromFaultEnvelope('GetPaymentDetails', 500, 'An error has occurred.')->getCode(),
            'The HTTP status must not be smuggled into the SPL code channel; statusCode() carries it.',
        );
        self::assertSame(
            0,
            SerializationException::malformedJson('InitPayment', new JsonException('Syntax error'))->getCode(),
        );
        self::assertSame(0, SerializationException::malformedXml('GetTransactionList', 'unexpected root')->getCode());
        self::assertSame(
            0,
            SerializationException::requestNotEncodable('InitPayment', new JsonException('Malformed UTF-8'))->getCode(),
        );
        self::assertSame(
            0,
            ConfigurationException::requestRejectedByClient('InitPayment', $previous::class, $previous)->getCode(),
        );

        self::assertSame(0, ValidationException::timeoutOutOfRange(1201)->getCode());
        self::assertSame(0, ValidationException::amountNotPositive(-4200)->getCode());
        self::assertSame(0, ValidationException::unsupportedPaymentType(7, 'GetBindings', [5, 6])->getCode());
        self::assertSame(0, ValidationException::blankValue('OrderID')->getCode());
        self::assertSame(0, ValidationException::malformedValue('PaymentID', 'a GUID')->getCode());
        self::assertSame(0, ValidationException::maxAttemptsOutOfRange(6)->getCode());
        self::assertSame(
            0,
            ValidationException::credentialFieldInRequestBody('InitPayment', 'Password')->getCode(),
        );
    }

    /**
     * Pinned whole, for the reason timeoutOutOfRange() is pinned whole: the
     * bounds are the message's only load-bearing content, and every substring
     * assertion available survives a mutation that widens them.
     *
     * The second sentence is not filler either. CONVENTIONS.md §4.5 fixes
     * which operations may be retried and states that it is not user
     * configurable, so a caller who reads only "must be between 1 and 5" could
     * reasonably expect a sixth attempt at a RefundPayment to be what was
     * refused. It is not: no value of this setting retries a refund at all.
     */
    public function testMaxAttemptsMessageNamesTheBoundsAndTheOffendingValue(): void
    {
        self::assertSame(
            'The maximum attempt count must be between 1 and 5, got 6. Only how '
            . 'many times a retryable operation is attempted is configurable; '
            . 'which operations may be retried at all is not.',
            ValidationException::maxAttemptsOutOfRange(6)->getMessage(),
        );
    }

    /**
     * Names the field, never a value — the same rule blankCredential() follows.
     *
     * `Password` is the interesting argument precisely because it is the one a
     * rogue request object would most want to smuggle, and naming it is what
     * CONVENTIONS.md §5 asks an exception message to do.
     */
    public function testCredentialFieldInRequestBodyNamesTheOperationAndTheField(): void
    {
        $exception = ValidationException::credentialFieldInRequestBody('MakeBindingPayment', 'Password');

        self::assertSame(
            'The MakeBindingPayment request body declared the credential field "Password". '
            . 'Credentials are merged by the transport and must never be built into a request object.',
            $exception->getMessage(),
        );
    }

    /**
     * A PSR-18 RequestExceptionInterface is a defect in the request, so it is a
     * ConfigurationException rather than a TransportException — and the message
     * has to say that it will not be retried, because the caller's instinct on
     * seeing any HTTP failure is to try again.
     *
     * The cause's own message is a leak channel: a client's exception text can
     * embed the request it refused, credentials included. Only its class name is
     * interpolated.
     */
    public function testRequestRejectedByClientNamesTheCauseWithoutLeakingItsMessage(): void
    {
        $previous = new RuntimeException('POST https://host/VPOS/api/VPOS/InitPayment sentinel-must-not-leak');
        $exception = ConfigurationException::requestRejectedByClient('InitPayment', $previous::class, $previous);

        self::assertSame(
            'The InitPayment request was rejected by the HTTP client before it was sent: '
            . RuntimeException::class . '. The request itself is malformed, so it is not retried.',
            $exception->getMessage(),
        );
        self::assertSame($previous, $exception->getPrevious());
        self::assertStringNotContainsString('sentinel-must-not-leak', $exception->getMessage());
    }

    /**
     * The encode direction of SerializationException, which causedByJson()
     * already anticipated: the class docblock says the failure runs "in either
     * direction: encoding a request or decoding a response", and until now only
     * the decoding half had a factory.
     */
    public function testRequestNotEncodableNamesTheCauseWithoutLeakingItsMessage(): void
    {
        $previous = new JsonException('Malformed UTF-8 characters, sentinel-payload-must-not-leak');
        $exception = SerializationException::requestNotEncodable('InitPayment', $previous);

        self::assertSame(
            'The InitPayment request could not be encoded as JSON: ' . JsonException::class,
            $exception->getMessage(),
        );
        self::assertSame('InitPayment', $exception->operation());
        self::assertTrue($exception->causedByJson());
        self::assertStringNotContainsString('sentinel-payload-must-not-leak', $exception->getMessage());
    }

    /**
     * Pinned whole, because a fragment cannot tell this message from the one
     * next door.
     *
     * The two callback refusals are deliberately different diagnoses of
     * superficially similar events — one says two order identifiers disagreed,
     * the other says the gateway named no order at all — and a merchant reading
     * a log has only the text to tell a possible replay attempt from a thin
     * response. Asserting "contains OrderID" would pass on either, and would
     * also pass on a message reduced to that one clause: the two sentences after
     * it are what tell the reader which side is authoritative and why neither
     * value is printed.
     *
     * Neither value is printed, and that is asserted rather than assumed. The
     * callback's `orderID` is attacker-controlled — CONVENTIONS.md §6 makes
     * BackURL parameters untrusted input — and an exception message reaches
     * logs, so interpolating it would hand an attacker a writable line in the
     * merchant's log. The factory takes no parameters at all, which is why
     * there is no sentinel to write down here.
     */
    public function testCallbackOrderMismatchNamesBothSidesWithoutPrintingEither(): void
    {
        $exception = ValidationException::callbackOrderMismatch();

        self::assertSame(
            'The callback\'s "orderID" and the GetPaymentDetails response\'s "OrderID" disagree, '
            . 'so this callback does not belong to the order it names. The callback is unsigned and '
            . 'untrusted; the response is authoritative. Neither value is reported here — the '
            . 'callback\'s is attacker-controlled and this message reaches logs.',
            $exception->getMessage(),
        );
        self::assertSame(0, $exception->getCode());
    }

    /**
     * Pinned whole for the same reason, and it is the message this branch
     * exists to have written.
     *
     * Until it existed this branch raised callbackOrderMismatch(), which states that
     * the callback "does not belong to the order it names" — false when the
     * response carried a blank `OrderID`, because nothing disagreed. The
     * callback may well belong to the order it names and nothing here can tell.
     * A false diagnosis in a security log is worse than a vague one: it points a
     * merchant at a replay attempt that may not have happened, and away from the
     * gateway behaviour that did.
     *
     * The message also has to say what to do, since refusing is not itself
     * actionable — hence the clause naming the two options, comparing the order
     * record by hand or treating the payment as unconfirmed.
     */
    public function testCallbackOrderUnconfirmableSaysNothingDisagreedAndWhatToDo(): void
    {
        $exception = ValidationException::callbackOrderUnconfirmable();

        self::assertSame(
            'The GetPaymentDetails response carried a blank "OrderID", so this callback\'s order '
            . 'identity could not be confirmed against it and the call is refused rather than '
            . 'trusted. Nothing disagreed — the gateway named no order. The callback is unsigned '
            . 'and untrusted, so its "paymentID" alone says nothing about which order was paid. '
            . 'Compare your own order record against the response yourself, or treat the payment '
            . 'as unconfirmed. The callback\'s value is not reported here — it is '
            . 'attacker-controlled and this message reaches logs.',
            $exception->getMessage(),
        );
        self::assertSame(0, $exception->getCode());
    }
}
