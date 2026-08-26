<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Tests\Response;

use function array_map;

use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Enum\OrderStatus;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentState;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;
use DavitVardanyan\AmeriabankVpos\Exception\SerializationException;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Response\ActivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\BankInfo;
use DavitVardanyan\AmeriabankVpos\Response\CancelPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\CardBindingFiled;
use DavitVardanyan\AmeriabankVpos\Response\ConfirmPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\DeactivateBindingResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetBindingsResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetPaymentIdResponse;
use DavitVardanyan\AmeriabankVpos\Response\GetPendingTransactionsResponse;
use DavitVardanyan\AmeriabankVpos\Response\InitPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\MakeBindingPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\PaymentDetailsResponse;
use DavitVardanyan\AmeriabankVpos\Response\RefundPaymentResponse;
use DavitVardanyan\AmeriabankVpos\Response\ResponseCode;
use DavitVardanyan\AmeriabankVpos\Support\ResponseHydrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The response DTOs are pure carriers, and this file is where "pure carrier"
 * is a testable claim rather than a description.
 *
 * Three properties are asserted, none of which the hydrator's own test can see:
 *
 * - fromWireArray() delegates. Every wire spelling exists as a literal exactly
 *   once in this package, inside ResponseHydrator, and a DTO that started
 *   reading `$data['PaymentID']` for itself would be a second copy of a
 *   mapping — which is the failure CONVENTIONS.md §2 and §4.8 exist to
 *   prevent, because the second copy drifts and a drifted key yields a silent
 *   null rather than an error.
 * - No constructor parameter has a default. Constructing PaymentDetailsResponse
 *   means naming all thirty-eight arguments, and that verbosity is the guard:
 *   a default would let a field be forgotten at a call site and read as "the
 *   gateway did not send this" forever after. It would also make the DTOs
 *   easier to build in a test, which is exactly the pressure it must resist.
 * - Every property is public readonly, and every class is final readonly.
 *
 * The named-argument constructions below are long on purpose and are not
 * generated at runtime from reflection. A reflection-driven construction would
 * pass whatever the constructor happened to declare and could never notice a
 * parameter being renamed — and a parameter name is public API here, because
 * CONVENTIONS.md §5 says these are constructed with named arguments.
 */
#[CoversClass(ActivateBindingResponse::class)]
#[CoversClass(BankInfo::class)]
#[CoversClass(CancelPaymentResponse::class)]
#[CoversClass(CardBindingFiled::class)]
#[CoversClass(ConfirmPaymentResponse::class)]
#[CoversClass(DeactivateBindingResponse::class)]
#[CoversClass(GetBindingsResponse::class)]
#[CoversClass(GetPaymentIdResponse::class)]
#[CoversClass(GetPendingTransactionsResponse::class)]
#[CoversClass(InitPaymentResponse::class)]
#[CoversClass(MakeBindingPaymentResponse::class)]
#[CoversClass(PaymentDetailsResponse::class)]
#[CoversClass(RefundPaymentResponse::class)]
#[UsesClass(Amount::class)]
#[UsesClass(Currency::class)]
#[UsesClass(OrderStatus::class)]
#[UsesClass(PaymentState::class)]
#[UsesClass(PaymentType::class)]
#[UsesClass(ResponseCode::class)]
#[UsesClass(ResponseHydrator::class)]
#[UsesClass(SerializationException::class)]
final class ResponseDtoTest extends TestCase
{
    /**
     * The thirteen response models, each with a payload and the hydrator method
     * its fromWireArray() must be a delegation to.
     *
     * The payload differs per model so that a DTO wired to the wrong hydrator
     * method fails on content rather than passing on two empty objects.
     *
     * @return array<string, array{class-string, callable(array<string, mixed>): object, array<string, mixed>}>
     */
    public static function models(): array
    {
        return [
            'InitPaymentResponse' => [
                InitPaymentResponse::class,
                ResponseHydrator::initPaymentResponse(...),
                ['PaymentID' => 'p-init', 'ResponseCode' => 1, 'ResponseMessage' => 'm-init'],
            ],
            'GetPaymentIdResponse' => [
                GetPaymentIdResponse::class,
                ResponseHydrator::getPaymentIdResponse(...),
                ['PaymentId' => 'p-get-id', 'ResponseCode' => '00', 'ResponseMessage' => 'm-get-id'],
            ],
            'GetPendingTransactionsResponse' => [
                GetPendingTransactionsResponse::class,
                ResponseHydrator::getPendingTransactionsResponse(...),
                ['OrderId' => 7001, 'ClientName' => 'c-pending', 'Amount' => '10.00'],
            ],
            'ConfirmPaymentResponse' => [
                ConfirmPaymentResponse::class,
                ResponseHydrator::confirmPaymentResponse(...),
                ['ResponseCode' => '00', 'ResponseMessage' => 'm-confirm', 'Opaque' => 'o-confirm'],
            ],
            'RefundPaymentResponse' => [
                RefundPaymentResponse::class,
                ResponseHydrator::refundPaymentResponse(...),
                ['ResponseCode' => '00', 'ResponseMessage' => 'm-refund', 'Opaque' => 'o-refund'],
            ],
            'CancelPaymentResponse' => [
                CancelPaymentResponse::class,
                ResponseHydrator::cancelPaymentResponse(...),
                ['ResponseCode' => '00', 'ResponseMessage' => 'm-cancel', 'Opaque' => 'o-cancel'],
            ],
            'ActivateBindingResponse' => [
                ActivateBindingResponse::class,
                ResponseHydrator::activateBindingResponse(...),
                ['ResponseCode' => '00', 'ResponseMessage' => 'm-activate', 'CardHolderID' => 'h-activate'],
            ],
            'DeactivateBindingResponse' => [
                DeactivateBindingResponse::class,
                ResponseHydrator::deactivateBindingResponse(...),
                ['ResponseCode' => '00', 'ResponseMessage' => 'm-deactivate', 'CardHolderID' => 'h-deactivate'],
            ],
            'GetBindingsResponse' => [
                GetBindingsResponse::class,
                ResponseHydrator::getBindingsResponse(...),
                [
                    'ResponseCode' => '00',
                    'ResponseMessage' => 'm-bindings',
                    'CardBindingFileds' => [['CardHolderID' => 'h-listed', 'IsAvtive' => true]],
                ],
            ],
            'CardBindingFiled' => [
                CardBindingFiled::class,
                ResponseHydrator::cardBindingFiled(...),
                ['CardHolderID' => 'h-single', 'CardPan' => '000000******0000', 'ExpDate' => '1230', 'IsAvtive' => false],
            ],
            'BankInfo' => [
                BankInfo::class,
                ResponseHydrator::bankInfo(...),
                ['BankName' => 'b-name', 'BankCountryCode' => 'AM', 'BankCountryName' => 'b-country'],
            ],
            'PaymentDetailsResponse' => [
                PaymentDetailsResponse::class,
                ResponseHydrator::paymentDetailsResponse(...),
                ['ResponseCode' => '00', 'OrderID' => 'o-details', 'Currency' => '051', 'Amount' => '10.00'],
            ],
            'MakeBindingPaymentResponse' => [
                MakeBindingPaymentResponse::class,
                ResponseHydrator::makeBindingPaymentResponse(...),
                ['ResponseCode' => '00', 'PaymentID' => 'p-binding', 'Currency' => '051', 'Amount' => '10.00'],
            ],
        ];
    }

    /**
     * The same thirteen models, class name only.
     *
     * A separate provider rather than three test methods ignoring two columns:
     * PHPUnit 12 reports an unused data-set column as a warning, and a warning
     * the suite is expected to carry is a warning nobody reads.
     *
     * @return array<string, array{class-string}>
     */
    public static function modelClasses(): array
    {
        $rows = [];

        foreach (self::models() as $label => [$model]) {
            $rows[$label] = [$model];
        }

        return $rows;
    }

    /**
     * fromWireArray() produces exactly what the hydrator produces.
     *
     * assertEquals rather than assertSame, because the two calls build two
     * objects; what is asserted is that they are the same object graph, field
     * for field, which is the whole content of "this is a delegation".
     *
     * Mutation demonstrated: pointing any DTO's fromWireArray() at a different
     * hydrator method, or having it read one key for itself, fails that row.
     *
     * @param class-string $model
     * @param callable(array<string, mixed>): object $hydrate
     * @param array<string, mixed> $wire
     */
    #[DataProvider('models')]
    public function testFromWireArrayIsADelegationToTheHydrator(string $model, callable $hydrate, array $wire): void
    {
        $viaDto = $model::fromWireArray($wire);
        $viaHydrator = $hydrate($wire);

        self::assertInstanceOf($model, $viaDto);
        self::assertInstanceOf($model, $viaHydrator);
        self::assertEquals($viaHydrator, $viaDto);
        self::assertNotSame($viaHydrator, $viaDto, 'Two calls, two objects — nothing is cached or shared.');
    }

    /**
     * No response DTO constructor has a default, an optional parameter, or a
     * variadic.
     *
     * This is the guard the thirty-eight-argument constructor is for. A default
     * would let a field be omitted at a call site and read afterwards as "the
     * gateway did not send this", which is indistinguishable from the truth and
     * wrong. It would also make these DTOs pleasant to build in a test, and
     * that pleasantness is precisely the pressure this assertion resists.
     *
     * @param class-string $model
     */
    #[DataProvider('modelClasses')]
    public function testEveryConstructorParameterIsRequired(string $model): void
    {
        $constructor = (new ReflectionClass($model))->getConstructor();

        self::assertNotNull($constructor);

        $optional = array_map(
            static fn(ReflectionParameter $p): string => $p->getName(),
            array_filter(
                $constructor->getParameters(),
                static fn(ReflectionParameter $p): bool => $p->isOptional() || $p->isVariadic(),
            ),
        );

        self::assertSame(
            [],
            array_values($optional),
            'A response DTO is a pure carrier. Naming every argument is the guard that stops a '
            . 'field from being forgotten and then read as an absence.',
        );
        self::assertSame(
            $constructor->getNumberOfParameters(),
            $constructor->getNumberOfRequiredParameters(),
        );
    }

    /**
     * Every response DTO is final and readonly, and every constructor parameter
     * is a promoted public property.
     *
     * CONVENTIONS.md §5 permits exactly one non-final class in src/ and it is
     * ApiException. A response DTO that became extensible would let a subclass
     * override a property the hydrator had just set from the wire.
     *
     * @param class-string $model
     */
    #[DataProvider('modelClasses')]
    public function testEveryResponseDtoIsFinalReadonlyWithPromotedPublicProperties(string $model): void
    {
        $reflection = new ReflectionClass($model);

        self::assertTrue($reflection->isFinal(), $model . ' must be final.');
        self::assertTrue($reflection->isReadOnly(), $model . ' must be readonly.');

        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            self::assertTrue(
                $parameter->isPromoted(),
                $model . '::$' . $parameter->getName() . ' must be a promoted property.',
            );
            self::assertTrue(
                $reflection->getProperty($parameter->getName())->isPublic(),
                $model . '::$' . $parameter->getName() . ' must be public.',
            );
        }
    }

    /**
     * Only ResponseCode and ResponseMessage are non-nullable, exactly as the
     * hydration rule requires.
     *
     * Every other field is nullable because the manifest declares none of them
     * required — its "Additional information" column reads "None." throughout —
     * so no field's presence is guaranteed. A DTO that declared, say, a
     * non-nullable `orderId` would be asserting something the gateway has never
     * promised. Probe case P3's completed payment does carry every declared
     * field, and probe B2's failed lookup does too, but a field observed present
     * is not a field contracted to be present.
     *
     * @param class-string $model
     */
    #[DataProvider('modelClasses')]
    public function testOnlyTheTwoObservedFieldsAreNonNullable(string $model): void
    {
        $constructor = (new ReflectionClass($model))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
                continue;
            }

            if (!$type instanceof ReflectionNamedType) {
                self::assertTrue(
                    $type?->allowsNull() ?? false,
                    $model . '::$' . $parameter->getName() . ' is a union and must admit null.',
                );

                continue;
            }

            self::assertContains(
                $parameter->getName(),
                ['responseCode', 'responseMessage'],
                $model . '::$' . $parameter->getName() . ' is non-nullable. That is permitted for '
                . 'ResponseCode and ResponseMessage only — every observed response has carried '
                . 'those two and nothing else has ever been observed at all.',
            );
        }
    }

    /**
     * PaymentDetailsResponse, constructed by naming all thirty-eight arguments,
     * with a distinct value in each.
     *
     * The assertion is against the whole object cast to an array, so it checks
     * declaration order as well as content: two arguments swapped between
     * same-typed neighbours — `merchantId` and `terminalId`, say — is the
     * copy-paste failure a constructor this wide invites, and it is invisible
     * to any per-field assertion using the same placeholder twice.
     */
    public function testPaymentDetailsResponseCarriesEveryNamedArgumentToItsOwnProperty(): void
    {
        $responseCode = ResponseCode::fromWire('00');
        $bankInfo = new BankInfo('v-bankName', 'v-bankCountryCode', 'v-bankCountryName');
        $amount = Amount::fromMinorUnits(1000, Currency::AMD);
        $approvedAmount = Amount::fromMinorUnits(900, Currency::AMD);
        $depositedAmount = Amount::fromMinorUnits(800, Currency::AMD);
        $refundedAmount = Amount::fromMinorUnits(700, Currency::AMD);

        $response = new PaymentDetailsResponse(
            amountRaw: 'v-amountRaw',
            amount: $amount,
            approvedAmountRaw: 'v-approvedAmountRaw',
            approvedAmount: $approvedAmount,
            approvalCode: 'v-approvalCode',
            cardNumber: 'v-cardNumber',
            clientName: 'v-clientName',
            clientEmail: 'v-clientEmail',
            currencyRaw: 'v-currencyRaw',
            currency: Currency::AMD,
            dateTime: 'v-dateTime',
            depositedAmountRaw: 'v-depositedAmountRaw',
            depositedAmount: $depositedAmount,
            description: 'v-description',
            mdOrderId: 'v-mdOrderId',
            merchantId: 'v-merchantId',
            terminalId: 'v-terminalId',
            orderId: 'v-orderId',
            paymentStateRaw: 'v-paymentStateRaw',
            paymentState: PaymentState::Deposited,
            paymentTypeRaw: 5,
            paymentType: PaymentType::MainRest,
            primaryRc: 'v-primaryRc',
            responseCode: $responseCode,
            expDate: 'v-expDate',
            processingIp: 'v-processingIp',
            orderStatusRaw: 'v-orderStatusRaw',
            orderStatus: OrderStatus::Deposited,
            cardHolderId: 'v-cardHolderId',
            bindingId: 'v-bindingId',
            refundedAmountRaw: 'v-refundedAmountRaw',
            refundedAmount: $refundedAmount,
            opaque: 'v-opaque',
            trxnDescription: 'v-trxnDescription',
            rrn: 'v-rrn',
            actionCode: 'v-actionCode',
            exchangeRate: 'v-exchangeRate',
            bankInfo: $bankInfo,
        );

        self::assertSame([
            'amountRaw' => 'v-amountRaw',
            'amount' => $amount,
            'approvedAmountRaw' => 'v-approvedAmountRaw',
            'approvedAmount' => $approvedAmount,
            'approvalCode' => 'v-approvalCode',
            'cardNumber' => 'v-cardNumber',
            'clientName' => 'v-clientName',
            'clientEmail' => 'v-clientEmail',
            'currencyRaw' => 'v-currencyRaw',
            'currency' => Currency::AMD,
            'dateTime' => 'v-dateTime',
            'depositedAmountRaw' => 'v-depositedAmountRaw',
            'depositedAmount' => $depositedAmount,
            'description' => 'v-description',
            'mdOrderId' => 'v-mdOrderId',
            'merchantId' => 'v-merchantId',
            'terminalId' => 'v-terminalId',
            'orderId' => 'v-orderId',
            'paymentStateRaw' => 'v-paymentStateRaw',
            'paymentState' => PaymentState::Deposited,
            'paymentTypeRaw' => 5,
            'paymentType' => PaymentType::MainRest,
            'primaryRc' => 'v-primaryRc',
            'responseCode' => $responseCode,
            'expDate' => 'v-expDate',
            'processingIp' => 'v-processingIp',
            'orderStatusRaw' => 'v-orderStatusRaw',
            'orderStatus' => OrderStatus::Deposited,
            'cardHolderId' => 'v-cardHolderId',
            'bindingId' => 'v-bindingId',
            'refundedAmountRaw' => 'v-refundedAmountRaw',
            'refundedAmount' => $refundedAmount,
            'opaque' => 'v-opaque',
            'trxnDescription' => 'v-trxnDescription',
            'rrn' => 'v-rrn',
            'actionCode' => 'v-actionCode',
            'exchangeRate' => 'v-exchangeRate',
            'bankInfo' => $bankInfo,
        ], (array) $response);
    }

    /**
     * MakeBindingPaymentResponse, likewise, with all thirty-nine.
     */
    public function testMakeBindingPaymentResponseCarriesEveryNamedArgumentToItsOwnProperty(): void
    {
        $responseCode = ResponseCode::fromWire('00');
        $amount = Amount::fromMinorUnits(1000, Currency::AMD);
        $approvedAmount = Amount::fromMinorUnits(900, Currency::AMD);
        $depositedAmount = Amount::fromMinorUnits(800, Currency::AMD);
        $refundedAmount = Amount::fromMinorUnits(700, Currency::AMD);

        $response = new MakeBindingPaymentResponse(
            paymentId: 'v-paymentId',
            responseCode: $responseCode,
            amountRaw: 'v-amountRaw',
            amount: $amount,
            approvedAmountRaw: 'v-approvedAmountRaw',
            approvedAmount: $approvedAmount,
            approvalCode: 'v-approvalCode',
            cardNumber: 'v-cardNumber',
            clientName: 'v-clientName',
            currencyRaw: 'v-currencyRaw',
            currency: Currency::AMD,
            dateTime: 'v-dateTime',
            depositedAmountRaw: 'v-depositedAmountRaw',
            depositedAmount: $depositedAmount,
            description: 'v-description',
            mdOrderId: 'v-mdOrderId',
            merchantId: 'v-merchantId',
            terminalId: 'v-terminalId',
            orderId: 'v-orderId',
            paymentStateRaw: 'v-paymentStateRaw',
            paymentState: PaymentState::Deposited,
            paymentTypeRaw: 5,
            paymentType: PaymentType::MainRest,
            primaryRc: 'v-primaryRc',
            expDate: 'v-expDate',
            processingIp: 'v-processingIp',
            orderStatusRaw: 'v-orderStatusRaw',
            orderStatus: OrderStatus::Deposited,
            cardHolderId: 'v-cardHolderId',
            bindingId: 'v-bindingId',
            refundedAmountRaw: 'v-refundedAmountRaw',
            refundedAmount: $refundedAmount,
            opaque: 'v-opaque',
            trxnDescription: 'v-trxnDescription',
            rrn: 'v-rrn',
            actionCode: 'v-actionCode',
            acsUrl: 'v-acsUrl',
            paReq: 'v-paReq',
            termUrl: 'v-termUrl',
        );

        self::assertSame([
            'paymentId' => 'v-paymentId',
            'responseCode' => $responseCode,
            'amountRaw' => 'v-amountRaw',
            'amount' => $amount,
            'approvedAmountRaw' => 'v-approvedAmountRaw',
            'approvedAmount' => $approvedAmount,
            'approvalCode' => 'v-approvalCode',
            'cardNumber' => 'v-cardNumber',
            'clientName' => 'v-clientName',
            'currencyRaw' => 'v-currencyRaw',
            'currency' => Currency::AMD,
            'dateTime' => 'v-dateTime',
            'depositedAmountRaw' => 'v-depositedAmountRaw',
            'depositedAmount' => $depositedAmount,
            'description' => 'v-description',
            'mdOrderId' => 'v-mdOrderId',
            'merchantId' => 'v-merchantId',
            'terminalId' => 'v-terminalId',
            'orderId' => 'v-orderId',
            'paymentStateRaw' => 'v-paymentStateRaw',
            'paymentState' => PaymentState::Deposited,
            'paymentTypeRaw' => 5,
            'paymentType' => PaymentType::MainRest,
            'primaryRc' => 'v-primaryRc',
            'expDate' => 'v-expDate',
            'processingIp' => 'v-processingIp',
            'orderStatusRaw' => 'v-orderStatusRaw',
            'orderStatus' => OrderStatus::Deposited,
            'cardHolderId' => 'v-cardHolderId',
            'bindingId' => 'v-bindingId',
            'refundedAmountRaw' => 'v-refundedAmountRaw',
            'refundedAmount' => $refundedAmount,
            'opaque' => 'v-opaque',
            'trxnDescription' => 'v-trxnDescription',
            'rrn' => 'v-rrn',
            'actionCode' => 'v-actionCode',
            'acsUrl' => 'v-acsUrl',
            'paReq' => 'v-paReq',
            'termUrl' => 'v-termUrl',
        ], (array) $response);
    }

    /**
     * The narrow DTOs, each built by naming its arguments and read back whole.
     *
     * Grouped because individually they are three lines apiece; distinct values
     * throughout, so that two of them wired to the same shape would show.
     */
    public function testTheNarrowDtosCarryTheirNamedArgumentsToTheirOwnProperties(): void
    {
        $responseCode = ResponseCode::fromWire('00');

        self::assertSame(
            ['paymentId' => 'v-paymentId', 'responseCode' => $responseCode, 'responseMessage' => 'v-message'],
            (array) new InitPaymentResponse(
                paymentId: 'v-paymentId',
                responseCode: $responseCode,
                responseMessage: 'v-message',
            ),
        );

        self::assertSame(
            ['paymentId' => 'v-paymentId', 'responseMessage' => 'v-message', 'responseCode' => $responseCode],
            (array) new GetPaymentIdResponse(
                paymentId: 'v-paymentId',
                responseMessage: 'v-message',
                responseCode: $responseCode,
            ),
        );

        self::assertSame(
            ['responseCode' => $responseCode, 'responseMessage' => 'v-message', 'opaque' => 'v-opaque'],
            (array) new ConfirmPaymentResponse(
                responseCode: $responseCode,
                responseMessage: 'v-message',
                opaque: 'v-opaque',
            ),
        );

        self::assertSame(
            ['responseCode' => $responseCode, 'responseMessage' => 'v-message', 'opaque' => 'v-opaque'],
            (array) new RefundPaymentResponse(
                responseCode: $responseCode,
                responseMessage: 'v-message',
                opaque: 'v-opaque',
            ),
        );

        self::assertSame(
            ['responseCode' => $responseCode, 'responseMessage' => 'v-message', 'opaque' => 'v-opaque'],
            (array) new CancelPaymentResponse(
                responseCode: $responseCode,
                responseMessage: 'v-message',
                opaque: 'v-opaque',
            ),
        );

        self::assertSame(
            ['responseCode' => $responseCode, 'responseMessage' => 'v-message', 'cardHolderId' => 'v-holder'],
            (array) new ActivateBindingResponse(
                responseCode: $responseCode,
                responseMessage: 'v-message',
                cardHolderId: 'v-holder',
            ),
        );

        self::assertSame(
            ['responseCode' => $responseCode, 'responseMessage' => 'v-message', 'cardHolderId' => 'v-holder'],
            (array) new DeactivateBindingResponse(
                responseCode: $responseCode,
                responseMessage: 'v-message',
                cardHolderId: 'v-holder',
            ),
        );

        self::assertSame(
            ['bankName' => 'v-name', 'bankCountryCode' => 'v-code', 'bankCountryName' => 'v-country'],
            (array) new BankInfo(
                bankName: 'v-name',
                bankCountryCode: 'v-code',
                bankCountryName: 'v-country',
            ),
        );

        self::assertSame(
            [
                'cardHolderId' => 'v-holder',
                'cardPan' => 'v-pan',
                'expDate' => 'v-expDate',
                'isActive' => true,
            ],
            (array) new CardBindingFiled(
                cardHolderId: 'v-holder',
                cardPan: 'v-pan',
                expDate: 'v-expDate',
                isActive: true,
            ),
        );

        self::assertSame(
            [
                'orderId' => 8001,
                'clientName' => 'v-clientName',
                'cardNumber' => 'v-cardNumber',
                'amountRaw' => 'v-amountRaw',
                'paymentDate' => 'v-paymentDate',
                'errorMessage' => 'v-errorMessage',
            ],
            (array) new GetPendingTransactionsResponse(
                orderId: 8001,
                clientName: 'v-clientName',
                cardNumber: 'v-cardNumber',
                amountRaw: 'v-amountRaw',
                paymentDate: 'v-paymentDate',
                errorMessage: 'v-errorMessage',
            ),
        );
    }

    /**
     * GetBindingsResponse carries the binding list it was given, by identity.
     *
     * Separate from the group above because its third argument is a list of
     * objects, and `assertSame` on the whole cast array would compare the array
     * by value; asserting the elements by identity is what proves nothing was
     * rebuilt on the way in.
     */
    public function testGetBindingsResponseCarriesTheListItWasGiven(): void
    {
        $responseCode = ResponseCode::fromWire('00');
        $first = new CardBindingFiled('h-1', 'v-pan-1', '1230', true);
        $second = new CardBindingFiled('h-2', 'v-pan-2', '1231', false);

        $response = new GetBindingsResponse(
            responseCode: $responseCode,
            responseMessage: 'v-message',
            cardBindings: [$first, $second],
        );

        self::assertSame($responseCode, $response->responseCode);
        self::assertSame('v-message', $response->responseMessage);
        self::assertSame([$first, $second], $response->cardBindings);
    }
}
