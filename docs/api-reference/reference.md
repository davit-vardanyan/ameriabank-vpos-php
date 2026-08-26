# vPOS API reference

Generated from `https://servicestest.ameriabank.am/VPOS/Help`.

This file is generated. Do not edit by hand. Field names are reproduced
verbatim from the upstream models, including upstream typos.

## Endpoints

### InitPayment

Make Payment

`POST api/VPOS/InitPayment`

**Request** — `InitPaymentRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `ClientID` | string | None. |
| `Amount` | decimal number | None. |
| `OrderID` | integer | None. |
| `BackURL` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `Description` | string | None. |
| `Currency` | string | None. |
| `CardHolderID` | string | None. |
| `Opaque` | string | None. |
| `Timeout` | integer | None. |
| `PaymentServiceType` | integer | None. |

**Response** — `InitPaymentResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `PaymentID` | string | None. |
| `ResponseCode` | integer | None. |
| `ResponseMessage` | string | None. |

### GetPaymentId

Get Payment Id

`POST api/VPOS/GetPaymentId`

**Request** — `GetPaymentIdRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `ClientID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `OrderID` | integer | None. |

**Response** — `GetPaymentIdResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `PaymentId` | string | None. |
| `ResponseMessage` | string | None. |
| `ResponseCode` | string | None. |

### GetPendingTransactions

Get Pending Transactions

`POST api/VPOS/GetPendingTransactions`

**Request** — `GetPendingTransactionsRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `ClientID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `StartDate` | date | None. |
| `EndDate` | date | None. |

**Response** — `GetPendingTransactionsResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `OrderId` | integer | None. |
| `ClientName` | string | None. |
| `CardNumber` | string | None. |
| `Amount` | decimal number | None. |
| `PaymentDate` | date | None. |
| `ErrorMessage` | string | None. |

### ConfirmPayment

Confirm Payment

`POST api/VPOS/ConfirmPayment`

**Request** — `ConfirmPaymentRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `PaymentID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `Amount` | decimal number | None. |

**Response** — `ConfirmPaymentResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `ResponseCode` | string | None. |
| `ResponseMessage` | string | None. |
| `Opaque` | string | None. |

### GetPaymentDetails

Get Order Status

`POST api/VPOS/GetPaymentDetails`

**Request** — `PaymentDetailsRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `PaymentID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |

**Response** — `PaymentDetailsResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `Amount` | decimal number | None. |
| `ApprovedAmount` | decimal number | None. |
| `ApprovalCode` | string | None. |
| `CardNumber` | string | None. |
| `ClientName` | string | None. |
| `ClientEmail` | string | None. |
| `Currency` | string | None. |
| `DateTime` | string | None. |
| `DepositedAmount` | decimal number | None. |
| `Description` | string | None. |
| `MDOrderID` | string | None. |
| `MerchantId` | string | None. |
| `TerminalId` | string | None. |
| `OrderID` | string | None. |
| `PaymentState` | string | None. |
| `PaymentType` | PaymentsEnum | None. |
| `PrimaryRC` | string | None. |
| `ResponseCode` | string | None. |
| `ExpDate` | string | None. |
| `ProcessingIP` | string | None. |
| `OrderStatus` | string | None. |
| `CardHolderID` | string | None. |
| `BindingID` | string | None. |
| `RefundedAmount` | decimal number | None. |
| `Opaque` | string | None. |
| `TrxnDescription` | string | None. |
| `rrn` | string | None. |
| `ActionCode` | string | None. |
| `ExchangeRate` | decimal number | None. |
| `BankInfo` | BankInfo | None. |

### RefundPayment

Refund Payment

`POST api/VPOS/RefundPayment`

**Request** — `RefundPaymentRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `PaymentID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `Amount` | decimal number | None. |

**Response** — `RefundPaymentResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `ResponseCode` | string | None. |
| `ResponseMessage` | string | None. |
| `Opaque` | string | None. |

### CancelPayment

Reverse Payment

`POST api/VPOS/CancelPayment`

**Request** — `CancelPaymentRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `PaymentID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |

**Response** — `CancelPaymentResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `ResponseCode` | string | None. |
| `ResponseMessage` | string | None. |
| `Opaque` | string | None. |

### GetBindings

Get Bindings

`POST api/VPOS/GetBindings`

**Request** — `GetBindingsRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `ClientID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `PaymentType` | PaymentsEnum | None. |

**Response** — `GetBindingsResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `ResponseCode` | string | None. |
| `ResponseMessage` | string | None. |
| `CardBindingFileds` | Collection of CardBindingFiled | None. |

### ActivateBinding

Activate Binding

`POST api/VPOS/ActivateBinding`

**Request** — `ActivateBindingRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `ClientID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `CardHolderID` | string | None. |
| `PaymentType` | PaymentsEnum | None. |

**Response** — `ActivateBindingResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `ResponseCode` | string | None. |
| `ResponseMessage` | string | None. |
| `CardHolderID` | string | None. |

### DeactivateBinding

Deactivate Binding

`POST api/VPOS/DeactivateBinding`

**Request** — `DeactivateBindingRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `ClientID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `CardHolderID` | string | None. |
| `PaymentType` | PaymentsEnum | None. |

**Response** — `DeactivateBindingResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `ResponseCode` | string | None. |
| `ResponseMessage` | string | None. |
| `CardHolderID` | string | None. |

### MakeBindingPayment

Do Binding Transaction

`POST api/VPOS/MakeBindingPayment`

**Request** — `MakeBindingPaymentRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `ClientID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `CardHolderID` | string | None. |
| `Amount` | decimal number | None. |
| `OrderID` | integer | None. |
| `BackURL` | string | None. |
| `PaymentType` | PaymentsEnum | None. |
| `Description` | string | None. |
| `Currency` | string | None. |
| `Opaque` | string | None. |

**Response** — `MakeBindingPaymentResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `PaymentID` | string | None. |
| `ResponseCode` | string | None. |
| `Amount` | decimal number | None. |
| `ApprovedAmount` | decimal number | None. |
| `ApprovalCode` | string | None. |
| `CardNumber` | string | None. |
| `ClientName` | string | None. |
| `Currency` | string | None. |
| `DateTime` | string | None. |
| `DepositedAmount` | decimal number | None. |
| `Description` | string | None. |
| `MDOrderID` | string | None. |
| `MerchantId` | string | None. |
| `TerminalId` | string | None. |
| `OrderID` | string | None. |
| `PaymentState` | string | None. |
| `PaymentType` | PaymentsEnum | None. |
| `PrimaryRC` | string | None. |
| `ExpDate` | string | None. |
| `ProcessingIP` | string | None. |
| `OrderStatus` | string | None. |
| `CardHolderID` | string | None. |
| `BindingID` | string | None. |
| `RefundedAmount` | decimal number | None. |
| `Opaque` | string | None. |
| `TrxnDescription` | string | None. |
| `rrn` | string | None. |
| `ActionCode` | string | None. |
| `AcsUrl` | string | None. |
| `PaReq` | string | None. |
| `TermUrl` | string | None. |

### SSNCheck

`POST api/VPOS/SSNCheck`

**Request** — `SSNCheckRequest`

| Name | Type | Additional information |
| --- | --- | --- |
| `ClientID` | string | None. |
| `Username` | string | None. |
| `Password` | string | None. |
| `PaymentID` | string | None. |
| `SSN` | string | None. |
| `IdentifierType` | IdentifierType | None. |

**Response** — `SSNCheckResponse`

| Name | Type | Additional information |
| --- | --- | --- |
| `Status` | string | None. |
| `Message` | string | None. |

## Models

### ActivateBindingRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ClientID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| CardHolderID |  | string | None. |
| PaymentType |  | PaymentsEnum | None. |

### ActivateBindingResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ResponseCode |  | string | None. |
| ResponseMessage |  | string | None. |
| CardHolderID |  | string | None. |

### BankInfo

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| BankName |  | string | None. |
| BankCountryCode |  | string | None. |
| BankCountryName |  | string | None. |

### CancelPaymentRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| PaymentID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |

### CancelPaymentResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ResponseCode |  | string | None. |
| ResponseMessage |  | string | None. |
| Opaque |  | string | None. |

### CardBindingFiled

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| CardHolderID |  | string | None. |
| CardPan |  | string | None. |
| ExpDate |  | string | None. |
| IsAvtive |  | boolean | None. |

### ConfirmPaymentRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| PaymentID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| Amount |  | decimal number | None. |

### ConfirmPaymentResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ResponseCode |  | string | None. |
| ResponseMessage |  | string | None. |
| Opaque |  | string | None. |

### DeactivateBindingRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ClientID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| CardHolderID |  | string | None. |
| PaymentType |  | PaymentsEnum | None. |

### DeactivateBindingResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ResponseCode |  | string | None. |
| ResponseMessage |  | string | None. |
| CardHolderID |  | string | None. |

### GetBindingsRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ClientID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| PaymentType |  | PaymentsEnum | None. |

### GetBindingsResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ResponseCode |  | string | None. |
| ResponseMessage |  | string | None. |
| CardBindingFileds |  | Collection of CardBindingFiled | None. |

### GetPaymentIdRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ClientID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| OrderID |  | integer | None. |

### GetPaymentIdResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| PaymentId |  | string | None. |
| ResponseMessage |  | string | None. |
| ResponseCode |  | string | None. |

### GetPendingTransactionsRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ClientID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| StartDate |  | date | None. |
| EndDate |  | date | None. |

### GetPendingTransactionsResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| OrderId |  | integer | None. |
| ClientName |  | string | None. |
| CardNumber |  | string | None. |
| Amount |  | decimal number | None. |
| PaymentDate |  | date | None. |
| ErrorMessage |  | string | None. |

### IdentifierType

| Name | Value | Description |
| --- | --- | --- |
| SSN | 1 |  |
| CertificateOfAbsenceOfSSN | 2 |  |
| ARMPassport | 3 |  |
| IdCard | 4 |  |
| InternationalPassport | 5 |  |

### InitPaymentRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ClientID |  | string | None. |
| Amount |  | decimal number | None. |
| OrderID |  | integer | None. |
| BackURL |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| Description |  | string | None. |
| Currency |  | string | None. |
| CardHolderID |  | string | None. |
| Opaque |  | string | None. |
| Timeout |  | integer | None. |
| PaymentServiceType |  | integer | None. |

### InitPaymentResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| PaymentID |  | string | None. |
| ResponseCode |  | integer | None. |
| ResponseMessage |  | string | None. |

### MakeBindingPaymentRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ClientID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| CardHolderID |  | string | None. |
| Amount |  | decimal number | None. |
| OrderID |  | integer | None. |
| BackURL |  | string | None. |
| PaymentType |  | PaymentsEnum | None. |
| Description |  | string | None. |
| Currency |  | string | None. |
| Opaque |  | string | None. |

### MakeBindingPaymentResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| PaymentID |  | string | None. |
| ResponseCode |  | string | None. |
| Amount |  | decimal number | None. |
| ApprovedAmount |  | decimal number | None. |
| ApprovalCode |  | string | None. |
| CardNumber |  | string | None. |
| ClientName |  | string | None. |
| Currency |  | string | None. |
| DateTime |  | string | None. |
| DepositedAmount |  | decimal number | None. |
| Description |  | string | None. |
| MDOrderID |  | string | None. |
| MerchantId |  | string | None. |
| TerminalId |  | string | None. |
| OrderID |  | string | None. |
| PaymentState |  | string | None. |
| PaymentType |  | PaymentsEnum | None. |
| PrimaryRC |  | string | None. |
| ExpDate |  | string | None. |
| ProcessingIP |  | string | None. |
| OrderStatus |  | string | None. |
| CardHolderID |  | string | None. |
| BindingID |  | string | None. |
| RefundedAmount |  | decimal number | None. |
| Opaque |  | string | None. |
| TrxnDescription |  | string | None. |
| rrn |  | string | None. |
| ActionCode |  | string | None. |
| AcsUrl |  | string | None. |
| PaReq |  | string | None. |
| TermUrl |  | string | None. |

### PaymentDetailsRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| PaymentID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |

### PaymentDetailsResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| Amount |  | decimal number | None. |
| ApprovedAmount |  | decimal number | None. |
| ApprovalCode |  | string | None. |
| CardNumber |  | string | None. |
| ClientName |  | string | None. |
| ClientEmail |  | string | None. |
| Currency |  | string | None. |
| DateTime |  | string | None. |
| DepositedAmount |  | decimal number | None. |
| Description |  | string | None. |
| MDOrderID |  | string | None. |
| MerchantId |  | string | None. |
| TerminalId |  | string | None. |
| OrderID |  | string | None. |
| PaymentState |  | string | None. |
| PaymentType |  | PaymentsEnum | None. |
| PrimaryRC |  | string | None. |
| ResponseCode |  | string | None. |
| ExpDate |  | string | None. |
| ProcessingIP |  | string | None. |
| OrderStatus |  | string | None. |
| CardHolderID |  | string | None. |
| BindingID |  | string | None. |
| RefundedAmount |  | decimal number | None. |
| Opaque |  | string | None. |
| TrxnDescription |  | string | None. |
| rrn |  | string | None. |
| ActionCode |  | string | None. |
| ExchangeRate |  | decimal number | None. |
| BankInfo |  | BankInfo | None. |

### PaymentsEnum

| Name | Value | Description |
| --- | --- | --- |
| None | 0 |  |
| Arca | 1 |  |
| MasterCard | 2 |  |
| Visa | 3 |  |
| Reward | 4 |  |
| MainRest | 5 |  |
| BindingMainRest | 6 |  |
| PayPal | 7 |  |
| PayX | 11 |  |
| MirCard | 12 |  |
| ApplePay | 13 |  |
| EPGCardApplePay | 14 |  |
| Amex | 17 |  |

### RefundPaymentRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| PaymentID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| Amount |  | decimal number | None. |

### RefundPaymentResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ResponseCode |  | string | None. |
| ResponseMessage |  | string | None. |
| Opaque |  | string | None. |

### SSNCheckRequest

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| ClientID |  | string | None. |
| Username |  | string | None. |
| Password |  | string | None. |
| PaymentID |  | string | None. |
| SSN |  | string | None. |
| IdentifierType |  | IdentifierType | None. |

### SSNCheckResponse

| Name | Description | Type | Additional information |
| --- | --- | --- | --- |
| Status |  | string | None. |
| Message |  | string | None. |

