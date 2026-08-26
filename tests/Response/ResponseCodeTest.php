<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Response;

use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\AuthenticationException;
use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;
use DavitVardanyan\AmeriabankVpos\Exception\DeclinedException;
use DavitVardanyan\AmeriabankVpos\Exception\DuplicateOrderException;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function var_export;

/**
 * ResponseCode decides, for every response this SDK will ever read, whether
 * the gateway said yes. Each assertion here was written against a named
 * mutation of src/Response/ResponseCode.php, and the mutation was applied and
 * observed red before the assertion was kept.
 *
 * Two habits that would make this file look tested without testing anything are
 * banned throughout, because both were live risks in exactly this class:
 *
 * - assertEquals, anywhere. The whole point of the class is that int 20 and
 *   string "20" are different wire values (CONVENTIONS.md §4.3), and
 *   assertEquals cannot tell them apart. Every assertion below is assertSame.
 * - assertInstanceOf on a toException() result. AuthenticationException extends
 *   ApiException, so assertInstanceOf(ApiException::class, ...) passes for an
 *   authentication failure and would have proved nothing about the dispatch.
 *   The class is asserted by ::class identity instead.
 *
 * Every claim this file makes about PHP's own comparison rules was executed on
 * PHP 8.3.28 before being written down, not reasoned about.
 */
#[CoversClass(ResponseCode::class)]
#[UsesClass(ApiException::class)]
#[UsesClass(AuthenticationException::class)]
#[UsesClass(ConfigurationException::class)]
#[UsesClass(DeclinedException::class)]
#[UsesClass(DuplicateOrderException::class)]
final class ResponseCodeTest extends TestCase
{
    /**
     * The three forms a success may take, and nothing else.
     *
     * One row each, not one test iterating the three: a loop with a single
     * assertion reports the first failure and stops, so dropping the second
     * entry from isSuccess()'s search set would be indistinguishable from
     * dropping the second and third. Three rows are three results.
     *
     * @return array<string, array{int|string}>
     */
    public static function successCodes(): array
    {
        return [
            'int 1 — observed on InitPayment' => [1],
            'string "1" — the same code through the XML representation' => ['1'],
            'string "00" — observed on GetPaymentDetails and RefundPayment' => ['00'],
        ];
    }

    /**
     * The ten counter-examples, in the order they were written.
     *
     * Three of them — int 0, string "0" and string "01" — are the ones that
     * pin the strict flag in isSuccess()'s in_array() call. Executed on PHP
     * 8.3.28: with the third argument dropped, "01" and int 0 and "0" all
     * report as members of [1, '1', '00'], because "01" == 1 and both 0 and
     * "0" == "00" under PHP 8's numeric-string comparison. The remaining seven
     * are unaffected by that mutation and are here for the plain fail-closed
     * rule: int 20 is the observed credential rejection and string "20" the
     * observed entitlement refusal, 560 the observed sandbox validation
     * failure, "0999" the observed BackURL code, and "", "OK" and 2 stand for
     * the codes nobody has seen yet. Neither form of 20 is success, whatever
     * either one means.
     *
     * @return array<string, array{int|string}>
     */
    public static function nonSuccessCodes(): array
    {
        return [
            'int 0' => [0],
            'string "0"' => ['0'],
            'int 20 — credential rejection on InitPayment' => [20],
            'string "20" — entitlement refusal from the binding endpoints' => ['20'],
            'int 560 — the sandbox amount rule' => [560],
            'string "0999" — observed on the BackURL callback' => ['0999'],
            'string "01"' => ['01'],
            'empty string' => [''],
            'string "OK" — a message where a code belongs' => ['OK'],
            'int 2' => [2],
        ];
    }

    /**
     * The one observed authentication failure, in the one type it arrived in.
     *
     * String "20" is deliberately not here. It is a different condition on the
     * wire, not the same condition in the other type — see
     * nonAuthenticationCodes() for the probe records and the reasoning.
     *
     * @return array<string, array{int}>
     */
    public static function authenticationFailures(): array
    {
        return [
            'int 20 — InitPayment, "Incorrect Username and Password"' => [20],
        ];
    }

    /**
     * Every code that must not be read as an authentication failure.
     *
     * The first row is the boundary, and the only observed wire value in the
     * set. String "20" occurs six times across the probe corpus and carries
     * ResponseMessage "Client payment type BindingMainRest is not available"
     * in all six — ActivateBinding and DeactivateBinding (cases A1.1 and A1.3)
     * and GetBindings (cases A11.4, A11.5, B6.1 and B6.2), every one of them
     * HTTP 200. That is an entitlement refusal.
     *
     * Int 20 occurs exactly once, and that occurrence is the deliberate
     * bad-password probe — case A2, whose recorded question is "Bad password:
     * does it 401/403 or 200 with ResponseCode 20?" — carrying "Incorrect
     * Username and Password". None of the six binding calls was a
     * bad-credential probe, and case A3 in the same phase answered
     * ResponseCode 1, "OK". Two conditions, so one classification: re-adding
     * `|| $this->raw === '20'` to isAuthenticationFailure() turns this
     * provider's test red.
     *
     * The remaining four resemble 20 without being it, each aimed at a
     * different wrong reading of the check, all four executed on PHP 8.3.28:
     *
     * - "020" is admitted by a loose ===-to-== change, since "020" == 20.
     * - "200" is admitted by a substring reading, str_contains("200", "20").
     * - int 2 and string "2" are admitted by the reversed substring reading,
     *   str_contains("20", "2"), which is the same typo written the other way
     *   round.
     *
     * The loose-comparison mutation is pinned twice over: 20 == "20" is also
     * true on PHP 8.3.28, so it admits the first row as well as "020".
     *
     * @return array<string, array{int|string}>
     */
    public static function nonAuthenticationCodes(): array
    {
        return [
            'string "20" — the entitlement refusal from ActivateBinding, DeactivateBinding, GetBindings' => ['20'],
            'string "020" — numerically equal to 20, textually not' => ['020'],
            'string "200"' => ['200'],
            'int 2' => [2],
            'string "2"' => ['2'],
        ];
    }

    /**
     * Failure codes that must produce a plain ApiException and no subclass.
     *
     * String "20" leads, because it is the row a later amendment moved here:
     * it is a failure the gateway has actually returned, and the one whose
     * classification changed. 560 and "0999" are observed too; "05" is the
     * PDF's decline code and is here precisely because it must not be
     * classified; "0151017" stands for the codes the bank adds without notice;
     * int 0 and "" are the degenerate shapes a malformed response could carry.
     *
     * @return array<string, array{int|string}>
     */
    public static function plainApiFailures(): array
    {
        return [
            'string "20" — the entitlement refusal, not a credential rejection' => ['20'],
            'int 560 — the sandbox amount rule' => [560],
            'string "0999" — observed on the BackURL callback' => ['0999'],
            'string "05" — the PDF calls this a decline' => ['05'],
            'string "0151017" — a shape the SDK has never seen' => ['0151017'],
            'int 0' => [0],
            'empty string' => [''],
        ];
    }

    /**
     * Every failure code this suite knows of, for the no-classification rule.
     *
     * Includes the four the PDF names — "01", "08204", "0100", "0116", its
     * duplicate-order and decline codes — alongside the observed failures. The
     * point of the breadth is that nothing anywhere reaches the two unmapped
     * classes, not merely that the PDF's four do not.
     *
     * @return array<string, array{int|string}>
     */
    public static function unmappedFailureCodes(): array
    {
        return [
            'string "01" — the PDF calls this a duplicate OrderID' => ['01'],
            'string "08204" — the PDF calls this a duplicate OrderID' => ['08204'],
            'string "0100" — the PDF calls this a decline' => ['0100'],
            'string "0116" — the PDF calls this insufficient funds' => ['0116'],
            'string "05" — the PDF calls this a decline' => ['05'],
            'int 20 — the one classification that does exist' => [20],
            'string "20"' => ['20'],
            'int 560' => [560],
            'string "0999"' => ['0999'],
            'int 0' => [0],
        ];
    }

    /**
     * The raw value comes back with the type it arrived with.
     *
     * assertSame, not assertEquals: under assertEquals both halves of this test
     * pass against a fromWire() that casts everything to string, which is the
     * one mutation it exists to catch. The type is asserted separately as well,
     * so the failure message says "int expected, string given" rather than
     * showing two values that print identically.
     */
    public function testTheRawValueKeepsTheTypeItArrivedWith(): void
    {
        $fromInitPayment = ResponseCode::fromWire(20);
        $fromEverythingElse = ResponseCode::fromWire('20');

        self::assertIsInt($fromInitPayment->raw(), 'InitPayment answers with an int (CONVENTIONS.md §4.3).');
        self::assertSame(20, $fromInitPayment->raw());

        self::assertIsString($fromEverythingElse->raw(), 'Every other endpoint answers with a string.');
        self::assertSame('20', $fromEverythingElse->raw());
    }

    /**
     * A leading zero that arrived as a string survives stringification.
     *
     * "00" and int 0 are different wire values, and so are "0999" and 999. All
     * three rows fail if the implementation routes through an integer anywhere:
     * executed on PHP 8.3.28, (string) (int) "00" is "0" and (string) (int)
     * "0999" is "999". The int 0 row is the counterweight — it is what stops
     * the leading-zero rule being satisfied by a blanket left-pad.
     */
    public function testAsStringPreservesALeadingZeroThatArrivedAsAString(): void
    {
        self::assertSame('00', ResponseCode::fromWire('00')->asString());
        self::assertSame('0', ResponseCode::fromWire(0)->asString());
        self::assertSame('0999', ResponseCode::fromWire('0999')->asString());
        self::assertSame('20', ResponseCode::fromWire(20)->asString());
    }

    #[DataProvider('successCodes')]
    public function testEachOfTheThreeSuccessFormsIsRecognised(int|string $raw): void
    {
        self::assertTrue(
            ResponseCode::fromWire($raw)->isSuccess(),
            sprintf('%s is one of the three forms a success may take.', var_export($raw, true)),
        );
    }

    /**
     * Fail-closed: everything outside the three forms is not success.
     *
     * Reporting a failure as success is the one misclassification that causes
     * an unpaid order to be treated as paid, so each counter-example is its own
     * test case and any single one being admitted is its own red result.
     */
    #[DataProvider('nonSuccessCodes')]
    public function testEveryOtherCodeIsNotSuccess(int|string $raw): void
    {
        self::assertFalse(
            ResponseCode::fromWire($raw)->isSuccess(),
            sprintf(
                '%s is not one of int 1, string "1", string "00", so isSuccess() must answer false. '
                . 'An unrecognised code is never success.',
                var_export($raw, true),
            ),
        );
    }

    /**
     * The providers above are hand-maintained, so they need completeness checks.
     *
     * Without them, a row deleted in a future edit is a code this suite no
     * longer pins, and nothing would say so — the deletion would simply reduce
     * the number of green cases.
     *
     * The two success lists are the ones the assertions were written against,
     * in that order. The three classification lists are the amendment's
     * membership.
     */
    public function testTheSuccessProviderHoldsExactlyTheThreeFormsAdmitted(): void
    {
        self::assertSame([1, '1', '00'], $this->providedCodes(self::successCodes()));
    }

    public function testTheNonSuccessProviderHoldsEveryCounterExample(): void
    {
        self::assertSame(
            [0, '0', 20, '20', 560, '0999', '01', '', 'OK', 2],
            $this->providedCodes(self::nonSuccessCodes()),
        );
    }

    /**
     * Integer 20 alone, and it must stay alone.
     *
     * Measured in both directions. Adding string "20" back turns this red, and
     * the two tests the provider feeds with it: both declare int $raw, so PHP
     * rejects the string argument outright — the narrowing is carried in the
     * signatures, not only in the assertions. Removing the int row turns this
     * red too, alongside PHPUnit's own "Empty data set provided by data
     * provider" error on the same two tests.
     */
    public function testTheAuthenticationProviderHoldsTheIntegerFormAlone(): void
    {
        self::assertSame(
            [20],
            $this->providedCodes(self::authenticationFailures()),
            'Deliberate: only integer 20 is a classified authentication failure. Adding string "20" here '
            . 'would re-introduce the classification that amendment removed.',
        );
    }

    /**
     * String "20" must stay in both sets that exercise the narrowing.
     *
     * Defence in depth, not the only defence — and the difference was measured
     * rather than assumed. With both rows deleted and both assertions here
     * relaxed to match, re-adding `|| $this->raw === '20'` to
     * isAuthenticationFailure() is still caught, by
     * testTheStringFormOfTwentyBecomesExactlyAnApiException, which states the
     * boundary directly and takes no provider. What these two assertions add is
     * that the narrowing keeps being exercised through the two shared dispatch
     * tests as well, so a provider edit cannot quietly reduce the boundary to a
     * single assertion without saying so.
     */
    public function testTheNonAuthenticationProviderStillHoldsTheStringFormOfTwenty(): void
    {
        self::assertSame(
            ['20', '020', '200', 2, '2'],
            $this->providedCodes(self::nonAuthenticationCodes()),
            'Deliberate: string "20" leads this list. Removing it would stop isAuthenticationFailure() '
            . 'being exercised against the string form at all.',
        );
    }

    public function testThePlainApiFailureProviderStillHoldsTheStringFormOfTwenty(): void
    {
        self::assertSame(
            ['20', 560, '0999', '05', '0151017', 0, ''],
            $this->providedCodes(self::plainApiFailures()),
            'Deliberate: string "20" leads this list. Removing it would stop toException() being exercised '
            . 'against the string form through this provider; the boundary itself is stated in '
            . 'testTheStringFormOfTwentyBecomesExactlyAnApiException.',
        );
    }

    /**
     * The credential rejection is still classified.
     *
     * The counterweight to the narrowing below. Something has to assert that
     * narrowing to integer 20 did not narrow to nothing: replacing the body of
     * isAuthenticationFailure() with `return false;` turns this red, and the
     * dispatch test further down with it (both measured). isAuthenticationFailure()
     * is public API in its own right, so it is asserted directly here rather
     * than only through toException().
     */
    #[DataProvider('authenticationFailures')]
    public function testTheIntegerFormOfTwentyIsAnAuthenticationFailure(int $raw): void
    {
        self::assertTrue(
            ResponseCode::fromWire($raw)->isAuthenticationFailure(),
            sprintf(
                '%s is the one observed credential rejection — InitPayment, ResponseMessage '
                . '"Incorrect Username and Password" (case A2). It must classify.',
                var_export($raw, true),
            ),
        );
    }

    /**
     * Nothing else is an authentication failure — string "20" included.
     *
     * THIS TEST ENCODES A DELIBERATE DECISION, NOT AN OVERSIGHT. The obvious
     * "fix" when it fails is to put string "20" back into
     * isAuthenticationFailure(); read the amendment recorded below and the
     * nonAuthenticationCodes() docblock first, because the probe corpus says
     * the two forms are different conditions.
     *
     * See nonAuthenticationCodes() for which mutation each of the other rows
     * catches. The cost of getting this wrong is a caller told to check its
     * credentials over a failure that has nothing to do with them — which for
     * string "20" is exactly what would happen: it means the client is not
     * entitled to the binding payment type, and no credential change fixes it.
     */
    #[DataProvider('nonAuthenticationCodes')]
    public function testNoOtherCodeIsAnAuthenticationFailure(int|string $raw): void
    {
        self::assertFalse(
            ResponseCode::fromWire($raw)->isAuthenticationFailure(),
            sprintf(
                'Deliberate: %s is not integer 20, the only form observed as a credential rejection. '
                . 'String "20" is in this set on purpose — all six of its occurrences carry "Client payment '
                . 'type BindingMainRest is not available", an entitlement refusal, so it is classified as '
                . 'nothing narrower than ApiException. A loose or substring comparison would admit these, '
                . 'and re-adding the string form would too; adding a classification later is reversible, '
                . 'removing one is not. See nonAuthenticationCodes().',
                var_export($raw, true),
            ),
        );
    }

    /**
     * equals() is strict about the wire type, and that is the intended answer.
     *
     * int 20 and string "20" are two conditions sharing one code: they arrived
     * from different endpoints carrying different ResponseMessages — "Incorrect
     * Username and Password" on InitPayment against "Client payment type
     * BindingMainRest is not available" on the three binding endpoints — and
     * only the integer form is a credential rejection. This value object exists
     * to preserve what arrived rather than paper over the difference. Executed
     * on PHP 8.3.28: both 20 == "20" and "20" == 20 are true, so the first two
     * assertions change when === becomes ==. The last two are the counterweight
     * that stops the mutation being "return false".
     */
    public function testEqualsIsStrictAboutTheWireType(): void
    {
        self::assertFalse(
            ResponseCode::fromWire(20)->equals(ResponseCode::fromWire('20')),
            'int 20 and string "20" are distinct wire values; == would call them equal.',
        );
        self::assertFalse(ResponseCode::fromWire('20')->equals(ResponseCode::fromWire(20)));

        self::assertTrue(ResponseCode::fromWire(20)->equals(ResponseCode::fromWire(20)));
        self::assertTrue(ResponseCode::fromWire('20')->equals(ResponseCode::fromWire('20')));
    }

    /**
     * Two different codes of the same type are not equal either.
     *
     * Without this, "equals() returns false whenever the types differ" and
     * "equals() compares the values" are indistinguishable.
     */
    public function testTwoDifferentCodesOfTheSameTypeAreNotEqual(): void
    {
        self::assertFalse(ResponseCode::fromWire('00')->equals(ResponseCode::fromWire('0')));
        self::assertFalse(ResponseCode::fromWire(20)->equals(ResponseCode::fromWire(560)));
    }

    /**
     * The credential rejection becomes exactly AuthenticationException.
     *
     * The class is asserted by ::class identity. assertInstanceOf would be
     * satisfied by the plain ApiException this dispatch exists to avoid,
     * because the subclass relationship runs the wrong way for that assertion.
     *
     * The raw code is asserted with its original type: it travels through
     * ApiException::responseCode(), which is int|string for this reason
     * (CONVENTIONS.md §5), and a cast anywhere on that path would erase the
     * distinction the rest of this file defends.
     */
    #[DataProvider('authenticationFailures')]
    public function testTheIntegerFormOfTwentyBecomesExactlyAnAuthenticationException(int $raw): void
    {
        $exception = ResponseCode::fromWire($raw)->toException('InitPayment', 'Incorrect Username and Password');

        self::assertSame(
            AuthenticationException::class,
            $exception::class,
            'Integer 20 is the observed credential rejection and must be classified as one. Only the integer '
            . 'form — see testTheStringFormOfTwentyBecomesExactlyAnApiException.',
        );
        self::assertSame('InitPayment', $exception->operation());
        self::assertIsInt($exception->responseCode(), 'InitPayment answers with an int (CONVENTIONS.md §4.3).');
        self::assertSame($raw, $exception->responseCode(), 'The raw code keeps its wire type through the exception.');
        self::assertSame('Incorrect Username and Password', $exception->responseMessage());
    }

    /**
     * The entitlement refusal becomes exactly ApiException, still a string.
     *
     * The boundary that amendment drew, stated once in its own test rather
     * than only as a provider row, because it is the assertion a future reader
     * will come looking for. Two independent things are pinned:
     *
     * - the class is ApiException itself, by ::class identity — assertInstanceOf
     *   would pass for AuthenticationException and prove nothing;
     * - the raw code is still the string "20", not int 20. Narrowing
     *   isAuthenticationFailure() to === 20 must not have tempted anything on
     *   this path into normalising the two forms to one, which would defeat the
     *   distinction the narrowing rests on.
     *
     * The ResponseMessage is the gateway's own entitlement text, which is how
     * the caller still learns what happened without a dedicated exception class.
     */
    public function testTheStringFormOfTwentyBecomesExactlyAnApiException(): void
    {
        $exception = ResponseCode::fromWire('20')->toException(
            'GetBindings',
            'Client payment type BindingMainRest is not available',
        );

        self::assertSame(
            ApiException::class,
            $exception::class,
            'Deliberate: string "20" is an entitlement refusal, not a credential rejection, in all six of its '
            . 'observed occurrences. It must not be classified as an AuthenticationException. See '
            . 'nonAuthenticationCodes().',
        );
        self::assertIsString($exception->responseCode(), 'GetBindings answers with a string (CONVENTIONS.md §4.3).');
        self::assertSame('20', $exception->responseCode(), 'The narrowing must not normalise "20" to 20.');
        self::assertSame('GetBindings', $exception->operation());
        self::assertSame('Client payment type BindingMainRest is not available', $exception->responseMessage());
    }

    /**
     * Every other failure becomes exactly ApiException — no subclass.
     *
     * ::class identity again, and here it is the assertion that carries the
     * whole test: assertInstanceOf(ApiException::class, ...) would stay green
     * if the dispatch classified all of these as AuthenticationException.
     */
    #[DataProvider('plainApiFailures')]
    public function testEveryOtherFailureBecomesExactlyAnApiException(int|string $raw): void
    {
        $exception = ResponseCode::fromWire($raw)->toException('GetPaymentDetails', 'Incorrect Parameters');

        self::assertSame(
            ApiException::class,
            $exception::class,
            sprintf(
                'Deliberate: %s is not an observed credential rejection and must not be classified as '
                . 'anything narrower than ApiException. String "20" is in this set by the same '
                . 'amendment — it is an entitlement refusal, and only integer 20 classifies.',
                var_export($raw, true),
            ),
        );
        self::assertSame('GetPaymentDetails', $exception->operation());
        self::assertSame($raw, $exception->responseCode(), 'The raw code keeps its wire type through the exception.');
        self::assertSame('Incorrect Parameters', $exception->responseMessage());
    }

    /**
     * Building an exception from a success code is a programming error.
     *
     * All three success forms, because the guard is isSuccess() and a guard
     * that only fired for int 1 would leave the string half of the API
     * returning a nonsensical ApiException reading "success failed".
     */
    #[DataProvider('successCodes')]
    public function testBuildingAnExceptionFromASuccessCodeIsAProgrammingError(int|string $raw): void
    {
        $this->expectException(ConfigurationException::class);

        ResponseCode::fromWire($raw)->toException('InitPayment', 'OK');
    }

    /**
     * The refusal names the code and the operation, in that order.
     *
     * Pinned whole. Asserting only the type leaves the two sprintf arguments
     * free to swap, which yields the confident nonsense "Response code
     * GetPaymentDetails from 00 is a success code"; asserting a fragment such
     * as "success code" leaves the whole message free to be replaced by a
     * constant that names neither. The code is rendered through asString(), so
     * this also fails if "00" reaches the message as "0".
     */
    public function testTheRefusalNamesTheCodeAndTheOperation(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(
            'Response code 00 from GetPaymentDetails is a success code; an exception cannot be built from it.',
        );

        ResponseCode::fromWire('00')->toException('GetPaymentDetails', 'OK');
    }

    /**
     * A failure code must still produce an exception rather than a refusal.
     *
     * The counterweight to the three rows above: inverting the success guard
     * satisfies none of them but would turn every real failure into a
     * ConfigurationException, and the dispatch tests would then fail for the
     * wrong reason. This states the rule directly.
     */
    public function testAFailureCodeIsNotMistakenForASuccessCode(): void
    {
        $exception = ResponseCode::fromWire(560)->toException('InitPayment', 'In test mode amount must be 10 AMD');

        self::assertSame(560, $exception->responseCode());
    }

    /**
     * No code produces DeclinedException or DuplicateOrderException.
     *
     * THIS TEST ENCODES A DELIBERATE DECISION, NOT AN OVERSIGHT. If it fails
     * because a mapping was added, the mapping is what needs justifying — read
     * the reasoning below and the toException() docblock before changing
     * anything here.
     *
     * The reasoning, in short: no decline has ever been observed. A card payment
     * has completed on the sandbox since — probe cases P1 through P6 — and it
     * was approved, so it supplies no decline code either; every decline code is
     * still PDF-sourced, and the PDF has already been wrong about endpoint
     * names, field types, enum membership, validation behaviour and the SOAP
     * envelope. That run also produced a reason to classify less: `"07"` came
     * back meaning an over-refund on P4.5 and a refused cancel on P5, told apart
     * only by ResponseMessage. On duplicate orders the PDF is contradicted
     * outright — probe A5 re-registered probe A3's OrderID and the gateway
     * answered ResponseCode 1, "OK", with A3's PaymentID, not the documented
     * "01".
     *
     * The asymmetry that settles it: adding a classification later is not a
     * breaking change, since both classes extend ApiException and a caller
     * catching ApiException keeps working. Removing a wrong one later is
     * breaking, because a caller catching DeclinedException silently stops
     * catching. Not classifying is the reversible choice, so it stays until a
     * real decline is observed on the wire.
     */
    #[DataProvider('unmappedFailureCodes')]
    public function testNoCodeIsClassifiedAsDeclinedOrDuplicate(int|string $raw): void
    {
        $exception = ResponseCode::fromWire($raw)->toException('ConfirmPayment', 'Declined');

        self::assertNotInstanceOf(
            DeclinedException::class,
            $exception,
            sprintf(
                'Deliberate: %s must not be classified as a decline. No decline has ever been observed on this '
                . 'gateway, so any mapping would be transcribed from a PDF that is already wrong elsewhere, and '
                . 'removing a wrong classification later is a breaking change.',
                var_export($raw, true),
            ),
        );
        self::assertNotInstanceOf(
            DuplicateOrderException::class,
            $exception,
            sprintf(
                'Deliberate: %s must not be classified as a duplicate order. Probe A5 re-registered an existing '
                . 'OrderID and the gateway answered ResponseCode 1, "OK" — the PDF\'s duplicate codes have never '
                . 'been seen, and what actually raises this condition is unknown.',
                var_export($raw, true),
            ),
        );
    }

    /**
     * Flattens a provider to the list of codes it carries, types intact.
     *
     * @param array<string, array{int|string}> $rows
     *
     * @return list<int|string>
     */
    private function providedCodes(array $rows): array
    {
        $codes = [];

        foreach ($rows as $row) {
            $codes[] = $row[0];
        }

        return $codes;
    }
}
