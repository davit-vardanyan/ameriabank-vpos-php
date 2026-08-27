**This is an unofficial client. It is not affiliated with, endorsed by, or supported by Ameriabank.**

# ameriabank-vpos-php

A framework-agnostic PHP client for the Ameriabank vPOS 3.1 payment gateway.

[![CI](https://github.com/davit-vardanyan/ameriabank-vpos-php/actions/workflows/ci.yml/badge.svg)](https://github.com/davit-vardanyan/ameriabank-vpos-php/actions/workflows/ci.yml)
[![Static analysis](https://github.com/davit-vardanyan/ameriabank-vpos-php/actions/workflows/static.yml/badge.svg)](https://github.com/davit-vardanyan/ameriabank-vpos-php/actions/workflows/static.yml)
[![Latest version](https://img.shields.io/packagist/v/davit-vardanyan/ameriabank-vpos-php.svg)](https://packagist.org/packages/davit-vardanyan/ameriabank-vpos-php)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%2010-brightgreen.svg)](phpstan.neon.dist)

> **Status: pre-release.** Nothing has been tagged yet. The enums, the `Amount`
> and `ResponseCode` value objects, the request and response DTOs, the PSR-18
> transport, the client surface that ties them together — `Vpos::payments()`
> and its siblings — and the BackURL callback type with the verification round
> trip it forces are all in place, so the REST payment lifecycle is usable
> end to end. Because nothing is tagged, the public API is not yet stable. SOAP
> reporting and `SSNCheck` are not implemented, and this README does not document
> methods that do not exist.

## Requirements

- PHP `^8.3`
- `ext-json`
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client (see below)

## Installation

```bash
composer require davit-vardanyan/ameriabank-vpos-php
```

### You must install a PSR-18 HTTP client separately

This package is **PSR-18 abstract**: it depends on the PSR-18, PSR-17, PSR-7,
and PSR-3 interfaces, and never on a concrete HTTP implementation. No HTTP
client is pulled in for you, so that the package does not force a second copy of
one into an application that already has its own.

You supply the client. Either install one that
[`php-http/discovery`](https://github.com/php-http/discovery) can find
automatically:

```bash
composer require guzzlehttp/guzzle
# or
composer require symfony/http-client nyholm/psr7
```

…or hand the client your application already has to `Vpos`'s `httpClient`
argument, which accepts any PSR-18 implementation. Leave it `null` and discovery
runs instead; if discovery finds nothing, you get a `ConfigurationException`
rather than a bare `php-http/discovery` error.

`guzzlehttp/guzzle` and `symfony/http-client` appear under `suggest` in
`composer.json`. They are never in `require`.

### Timeouts belong to the client you inject

**PSR-18 has no timeout API.** Its whole surface is one method, `sendRequest()`,
and there is nowhere in it to say how long the caller is willing to wait. This
package therefore cannot bound a request portably, and deliberately does not
try: there is no timeout option on any class here, and adding one would mean
depending on a concrete HTTP implementation, which this package does not do.

The client is yours, so the timeout is yours. Set it when you build the client,
before you hand it over.

With Guzzle:

```php
use GuzzleHttp\Client;

$httpClient = new Client([
    'connect_timeout' => 3.0,  // seconds allowed to establish the connection
    'timeout' => 10.0,         // seconds allowed for the whole request
]);
```

With Symfony's HTTP client:

```php
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$httpClient = new Psr18Client(HttpClient::create([
    'timeout' => 10.0,       // seconds of inactivity before the connection drops
    'max_duration' => 15.0,  // seconds allowed for the whole request
]));
```

Both of those are configuration for software you chose. Neither package is a
dependency of this one — they are `suggest` entries and nothing more — and any
other PSR-18 implementation works the same way, with whatever option names it
happens to use.

#### `Timeout` in `InitPaymentRequest` is a different thing entirely

`InitPaymentRequest` takes a `timeout`. **It is not an HTTP timeout.** It is a
gateway-side session length in seconds — how long the payment page stays valid
for the customer after the payment is registered — and it bounds nothing about
the HTTP request that registers it:

```php
use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Request\InitPaymentRequest;

$request = new InitPaymentRequest(
    amount: Amount::fromDecimalString('10.00', Currency::AMD),
    orderId: 1001,
    backUrl: 'https://merchant.example/vpos/return',
    timeout: 900,  // the payment page stays valid for 15 minutes
);
```

Setting one does not substitute for the other, in either direction. A
`timeout: 900` on an injected client with no timeout of its own is a request
that can hang indefinitely while offering the customer a quarter of an hour to
pay.

This library validates the value to `1..1200` seconds and throws
`ValidationException` outside that range. The gateway does not validate it at
all — 1201, 0 and −1 were each accepted silently on the sandbox — so without the
client-side check a typo would become a payment page that expires at the wrong
moment rather than an error.

#### Why the timeout you choose is a real decision

Four operations move money and are never retried: `ConfirmPayment`,
`RefundPayment`, `CancelPayment` and `MakeBindingPayment`. A timeout is a
transport failure, and when one of these fails in transport this library cannot
know whether the gateway acted, so it throws `IndeterminateStateException` after
exactly one attempt. That exception tells the caller to reconcile through
`GetPaymentDetails` and not to retry, and it is deliberately not a subtype of
`TransportException`, so a `catch` written to retry transport failures cannot
swallow it.

A timeout set too short therefore does not merely fail a request. It can turn a
capture or a refund the gateway may well have completed into work a human has to
reconcile. The read-only operations and `InitPayment` are retried automatically
instead — `InitPayment` only ever with the byte-identical body it first sent —
so the cost of a short timeout is not the same across operations.

The one completed sandbox payment behind this package puts numbers on that, and
they do not fall the way "writes are slower than reads" would suggest. A
**rejected** write answers at read speed: both refusals on record — an
over-refund and a cancel that the payment's state forbade — came back in about
102 ms, faster than every `GetPaymentDetails` call in the same run, which took
145–174 ms. `InitPayment` took 61 ms. The two refunds that actually moved money
took 518 ms and 910 ms. The cost is in the settlement, not in the verb, so the
slowest operations are exactly the ones this package will never retry: a timeout
short enough to cut one of them off buys you an `IndeterminateStateException`
and a manual reconciliation, not a second attempt. Those are one sandbox and one
payment — read them as orders of magnitude rather than as a budget
([`CONVENTIONS.md`](CONVENTIONS.md) §4.21).

## Usage

`Vpos` is the entry point and the only class you construct. It owns one
transport and exposes three operation clients — `payments()`, `bindings()` and
`reports()` — plus `paymentPageUrl()`, which performs no API call.

```php
use DavitVardanyan\AmeriabankVpos\Config\Credentials;
use DavitVardanyan\AmeriabankVpos\Config\Environment;
use DavitVardanyan\AmeriabankVpos\Vpos;

$vpos = new Vpos(
    credentials: new Credentials(
        clientId: (string) getenv('VPOS_CLIENT_ID'),
        username: (string) getenv('VPOS_USERNAME'),
        password: (string) getenv('VPOS_PASSWORD'),
    ),
    environment: Environment::Test,
);

Vpos::PROTOCOL_VERSION; // '3.1' — the wire protocol this client targets
```

Never hard-code credentials in source. Read `ClientID`, username and password
from your environment or secret store, as above. A blank one throws
`ConfigurationException` at construction, rather than reaching the gateway and
coming back as something that looks like a wrong password.

`environment` is required and has no default, deliberately. Defaulting to `Test`
means a misconfigured deployment silently takes no money and finds out at
reconciliation weeks later; defaulting to `Production` means a developer who
forgets it hits live infrastructure. Naming it has neither failure mode.

### The payment lifecycle

Four steps: register the order, send the customer to the payment page, ask the
gateway what happened, and — if you have to — give the money back.

**1. Register the order.** `InitPayment` returns the `PaymentID` the payment
page is keyed on.

```php
use DavitVardanyan\AmeriabankVpos\Enum\Currency;
use DavitVardanyan\AmeriabankVpos\Money\Amount;
use DavitVardanyan\AmeriabankVpos\Request\InitPaymentRequest;

$init = $vpos->payments()->init(new InitPaymentRequest(
    amount: Amount::fromMinorUnits(1000, Currency::AMD),  // 10.00 AMD
    orderId: 1001,                                        // your own order number
    backUrl: 'https://merchant.example/vpos/return',
    description: 'Order 1001',
    opaque: 'cart-9f3c',                                  // echoed back to you untouched
    timeout: 900,                                         // payment page valid for 15 minutes
));

$paymentId = $init->paymentId ?? '';
```

**Set `Description` on the request, and read `trxnDescription` back off the
response.** The symmetry you expect is not there: `GetPaymentDetails` returns
your submitted text in `TrxnDescription` and overwrites the response's own
`Description` with the processor's wording — `Approved. - Payment post
authorized` on a deposited payment, `Approved. - Refunded payment back to client
card` once a refund has run. `$details->description` is the gateway talking, not
you (CONVENTIONS.md §4.15).

**That echo is not byte-exact, so never use a non-ASCII `Description` as a
reconciliation key.** An Armenian `Description` came back from
`TrxnDescription` with every Armenian codepoint replaced by `¿` (U+00BF); the
codepoint count and the ASCII prefix survived, the letters did not. Reconcile on
`OrderID`, or on an ASCII value you put in `Opaque`, and treat
`trxnDescription` as text for a human to read rather than a value to compare.
Scope this exactly as it was observed: Armenian only, `Description` →
`TrxnDescription` only, on the test environment. Russian and other scripts are
untested, and the request itself is accepted — the package sends your text as raw
UTF-8 and the gateway takes it. The question of why the value comes back altered
is with the bank (CONVENTIONS.md §4.15, §13).

`Amount` holds an integer minor-unit count and a `Currency`; there is no
constructor taking a float, and none will be added. Build one with
`Amount::fromMinorUnits(1000, Currency::AMD)` or
`Amount::fromDecimalString('10.00', Currency::AMD)`. Only `Currency::AMD` has
ever been accepted by the gateway — see [Unverified behaviour](#unverified-behaviour).

**2. Send the customer to the payment page.**

```php
use DavitVardanyan\AmeriabankVpos\Enum\Language;

header('Location: ' . $vpos->paymentPageUrl($paymentId, Language::Armenian), true, 303);
```

`paymentPageUrl()` refuses a blank `PaymentID` rather than building a broken
page. That matters because a failed `InitPayment` answers with `"PaymentID": ""`
— an empty string, never null — so a caller who reached this line holding
nothing gets an exception instead of a customer staring at an error page.

**3. Read the outcome — from the gateway, never from the callback.**

The customer comes back to your `backUrl` with a query string like:

```
?orderID=1001&resposneCode=00&paymentID=1234abcd-…&opaque=cart-9f3c&description=…
```

**Those parameters are unsigned. Never trust them.** There is no HMAC, no
signature and no shared secret anywhere in this callback, so anyone who can type
a URL can send you `resposneCode=00` for an order nobody paid for. The library
gives you no way to read a status out of them, because there is no safe way to
read one.

Treat the callback as a notification that *something* happened, take its
identifiers as lookup keys and nothing more, and ask the gateway:

```php
use DavitVardanyan\AmeriabankVpos\Callback\VposCallback;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;

try {
    $callback = VposCallback::fromQuery($_GET);   // unsigned, forgeable, identifiers only
    $details  = $vpos->verify($callback);         // the round trip that actually answers
} catch (VposExceptionInterface $e) {
    // An unusable callback, an OrderID that is not the callback's, or a gateway
    // that would not answer. The payment is unconfirmed: reconcile it later and
    // release nothing now. This path is not the exception — see below.
    return;
}

$details->orderId;             // ?string — the order the gateway says this payment is for
$details->orderStatusRaw;      // ?string, exactly as the gateway sent it
$details->orderStatus;         // ?OrderStatus — null when this SDK does not know the raw value
$details->paymentStateRaw;     // ?string — a second, separate status field, also populated
$details->paymentState;        // ?PaymentState — null when this SDK does not know the raw value
$details->approvedAmountRaw;   // ?string, the authorised total — the amount to compare against yours
$details->depositedAmountRaw;  // ?string, the remaining refundable balance, not the captured total
$details->refundedAmountRaw;   // ?string, refunded so far — accumulates across partial refunds
$details->approvedAmount;      // ?Amount — typed companion; null when no currency or a zero value
$details->trxnDescription;     // ?string — the Description *you* submitted
$details->description;         // ?string — the processor's own wording, not yours
$details->rrn;                 // ?string, the acquirer's retrieval reference number
```

Every amount arrives twice: a raw string exactly as the gateway sent it, and a
typed `Amount` beside it. The raw one is the field you can always read — the
typed one is `null` unless the same response also carried a currency *and* the
value is non-zero, so on a payment that has not been refunded `refundedAmount`
is `null` while `refundedAmountRaw` is `"0.0"`.

`rrn` and `mdOrderId` came back byte-identical on every body observed, so
reconciling on both is reconciling on the same value twice
(CONVENTIONS.md §4.19).

`VposCallback::fromServerRequest($request)` does the same from a PSR-7
`ServerRequestInterface`, so no framework is assumed.

Either constructor throws `ValidationException` when `paymentID` or `orderID` is
missing or blank, because a callback with no identifiers has nothing to verify.
Both match the five parameter names exactly and case-sensitively — `resposneCode`
misspelling included, since that is the wire spelling — so a spelling or case
variant is not quietly absorbed.

**The case of a *value* is pinned for the same reason, and it will surprise you
once.** The lowercase `paymentID` in the query string above is not a typo:
`InitPayment` returns that identifier in uppercase and the callback echoes the
very same identifier in lowercase. This package normalises neither, because the
case a channel sends is part of that channel's wire format. Compare the two
case-insensitively, or compare `orderID` instead; a case-sensitive `===` between
them reports a mismatch that is not one (CONVENTIONS.md §4.12). The callback's
`description` needs the same care for a different reason: **both** successful
callbacks on record delivered `Operation Approved ` with a **trailing space**,
handed back verbatim rather than trimmed. The second arrived byte-identical to
the first, trailing space included, on a different payment and a different
order — so the space is not a one-off oddity of a single redirect that a future
call might tidy up. Two observations is still two, but they agree. Log the
value; never compare against it.

`verify()` is `details()` plus one check you would otherwise have to write
yourself, and it is worth knowing which. The `paymentID` in that query string is
attacker-controlled like everything else in it, and a `PaymentID` is not a secret
— it was handed to the customer's browser — so somebody can put a **genuine**
`paymentID` from a stranger's paid order on your `backUrl` beside *their own*
`orderID`, and a bare `details()` call will answer, truthfully, that a payment
succeeded. `verify()` closes that: when the response names an `OrderID`, it must
be identical to the callback's or this throws `ValidationException`; and a
response naming a *blank* `OrderID` — empty or whitespace-only — is refused too,
for a distinct reason and with a distinct message, since nothing disagreed there
and there was simply no order identity to check against. The comparison itself is
exact — no trimming, no case folding, no numeric coercion, the one trim being the
blank test — because `'1001'` and `'01001'` are different orders.

That is one check of three, and it is the only one `verify()` makes. A call that
returns proves that the gateway answered, not that money moved — and on the
unhappy path it will often not return at all but throw, since every
`GetPaymentDetails` observation on record against a payment that never went
through is a failure
(see [A failed operation throws](#a-failed-operation-throws-it-does-not-return-a-code)).
So handle the throw, and before you treat anything as paid, all three of these
must hold:

- the order identity — **`verify()` does this one for you, and only when the
  response carries an `OrderID`.** If that field comes back absent or null the
  check is skipped and you get no order-identity protection from it; if it comes
  back blank it is refused rather than skipped, so the three outcomes are match →
  pass, blank → refuse, absent → skip. Both completed payments on record produced
  the first of the three — a populated `OrderID`, identical to the callback's,
  reaching the comparison and passing it, on the second one through `verify()`
  itself — but that is two payments on one sandbox client, so keep your own
  record and compare `$details->orderId` against it if you hold one;
- `approvedAmountRaw` is the amount you asked for. Compare **that** one, not
  `depositedAmountRaw`: `DepositedAmount` is the remaining refundable balance
  and decrements as refunds run, so it is correct before a refund and wrong
  after one, while `ApprovedAmount` is the authorised total and does not move
  (CONVENTIONS.md §4.16). Compare the raw string rather than the typed
  `Amount`: an `Amount` needs a currency, and this SDK builds one only from the
  currency the *same response* carried, never from a default. The completed
  payment did carry one — `"051"` — so the typed `amount`, `approvedAmount` and
  `depositedAmount` were all populated on it; `refundedAmount` was still `null`,
  because the constructor refuses a zero and nothing had been refunded yet. A
  value that is missing is not a match. Fail closed there too;
- `orderStatusRaw` (or `orderStatus`) is a status you positively recognise as
  paid — and **anything you cannot positively recognise is not paid.** Fail
  closed. The same goes for `paymentStateRaw`: these are two separate fields,
  both populated, and both worth reading.

This library will not make that last check for you, because it cannot. One
completed sandbox payment carried `OrderStatus` `"2"` beside `PaymentState`
`payment_deposited`, and `"4"` beside `payment_refunded` after a refund — but
`"4"` appeared on a payment that was only **partially** refunded, with a balance
still outstanding, so the refunded status does not mean fully refunded and a
merchant who reads the status and skips the amounts above will get it wrong
(CONVENTIONS.md §4.18). One payment on one sandbox client is not the status
table for your account. Recognise the statuses you have watched settle in your
own account, and treat every other status as unpaid.

`VposCallback` exposes no success accessor, no status and no response code —
there is nothing there that could implement one honestly. It does keep the two
diagnostics, reachable only as `$callback->untrustedDiagnostics()`, an array
carrying `resposneCode` and `description` as they arrived. Log them on a failed
callback, where the gateway's error text is often the only clue you get. Do not
branch on them: both are attacker-controlled, and reading `['resposneCode']` to
decide anything is exactly the mistake the array shape exists to make visible at
the call site.

Every enum-typed field arrives in pairs — a raw value and a nullable enum. The
enum is `null` when the gateway sent something this SDK does not yet recognise,
which is a value the bank added without notice, not an error. Read the raw when
the enum is null; never assume the enum being null means the field was absent.

**4. Refund, in full or in part.**

```php
$refund = $vpos->payments()->refund(
    $paymentId,
    Amount::fromMinorUnits(1000, Currency::AMD),
);

$refund->responseCode->asString();  // '00'
```

Partial refunds accumulate, and the field that tells you what is left is
`depositedAmountRaw` on a fresh `details()` read — a payment of 10 refunded by 4
and then by 3 reported `DepositedAmount` 10, then 6, then 3, while
`RefundedAmount` went 0, then 4, then 7 and `ApprovedAmount` stayed at 10
throughout. Ask for more than the balance and the gateway refuses rather than
clamping: `ResponseCode` `"07"`, `Refund amount exceeds deposited amount`,
raised here as `ApiException`.

Do not treat `"07"` as a single condition. A `cancel()` that the payment's state
forbids answers `"07"` as well, with `Reversal is impossible for current
transaction state`. The two are told apart only by `responseMessage()`, which is
why no response code is mapped to a dedicated exception subclass and why this
package ships no code-to-description table: the vendor PDF calls `07` "System
Error", and a transcribed table would have printed that over the top of two
accurate messages the gateway had already sent (CONVENTIONS.md §4.17). The
success message varies by endpoint too — `InitPayment` answers `"OK"` and
`RefundPayment` answers `"Success"` — so read `responseMessage()` rather than
assuming a word.

`confirm()` captures an authorised payment and `cancel()` voids one before
capture; both take the same shape. None of the three is ever retried — see
below.

### A failed operation throws; it does not return a code

Nothing above checks a response code, and that is deliberate. HTTP status
carries no business meaning at this gateway — an authentication failure arrives
as HTTP 200, and a well-formed request can come back as HTTP 500 — so this
library decides success from the body and raises on anything else. **A method
that returns has succeeded**; the response object is there to tell you the
details, not whether it worked.

That is a claim about the *call*, not about the payment. On a read such as
`details()` or `verify()`, a return means the gateway answered the question you
asked — nothing more. The payment's own outcome is in the body, and reading it is
step 3's three checks, not this method's return.

Do not read the converse into it either: a query about a payment that never
completed does **not** reliably come back at all. Three response shapes have
been seen from `GetPaymentDetails`, and only one of them returns. Every lookup
made with valid credentials against a payment that had actually been paid
answered HTTP 200 with `ResponseCode` `"00"` and a fully populated body. Every
other observation is a failure, and so throws, in one of two shapes: HTTP 500
with the ASP.NET envelope `{"Message":"An error has occurred."}`, which surfaces
as `GatewayFaultException`, or HTTP 200 carrying `ResponseCode` `"550"`, which
surfaces as `ApiException`. What separates those two failure shapes from each
other has never been explained
(see [Unverified behaviour](#unverified-behaviour)).

`"550"` in particular tells you less than it looks like it does, and that is
worth knowing before you debug one. As recorded in CONVENTIONS.md §4.25, it has
arrived for an order that was registered and never attempted, asked about with
**correct** credentials; and it has arrived for a payment that had completed,
asked about with a **wrong password** (case L5.3). Neither of those is the
meaning of the code — it is overloaded, exactly as `"07"` is (§4.17). Nor does
the message narrow it down, because there is none: this endpoint sends no
`ResponseMessage` at all, so the exception reads `… failed with response code
550: (no message)`, and the only diagnostic text on the body is
`Description "System Error"`, which a failure code leaves no response object to
read off. Suspect the credentials you sent as readily as the order you asked
about.

So expect the unhappy path to **throw** rather than to hand you a body to
inspect, and catch both — a thrown `GatewayFaultException` means the gateway
would not answer, which is evidence neither that the payment happened nor that
it did not, and on this endpoint it is not evidence about your credentials
either: a wrong password against an order that produces the fault produces the
fault, not a `"550"` (CONVENTIONS.md §4.26).

```php
use DavitVardanyan\AmeriabankVpos\Exception\ApiException;
use DavitVardanyan\AmeriabankVpos\Exception\IndeterminateStateException;
use DavitVardanyan\AmeriabankVpos\Exception\VposExceptionInterface;

try {
    $refund = $vpos->payments()->refund($paymentId, $amount);
} catch (IndeterminateStateException $e) {
    // The exchange never completed, and the refund may or may not have happened.
    // Do not retry. Reconcile:
    $details = $vpos->payments()->details($e->paymentId() ?? $paymentId);
} catch (ApiException $e) {
    // The gateway answered, and the answer was no.
    $e->responseCode();     // int|string, keeping the type the wire sent
    $e->responseMessage();
} catch (VposExceptionInterface $e) {
    // Everything else this package throws: a field rejected before dispatch,
    // a transport failure, a misconfigured client, a gateway fault, a body
    // that could not be mapped.
}
```

`catch (VposExceptionInterface)` catches everything from this package and
nothing else. `IndeterminateStateException` is deliberately *not* a
`TransportException`, so a `catch` written to retry transport failures cannot
swallow the one case where retrying may refund twice.

### Bindings and reporting

```php
use DateTimeImmutable;
use DavitVardanyan\AmeriabankVpos\Enum\PaymentType;

$bindings = $vpos->bindings()->all(PaymentType::BindingMainRest);
$vpos->bindings()->activate('card-holder-id', PaymentType::BindingMainRest);
$vpos->bindings()->deactivate('card-holder-id', PaymentType::BindingMainRest);
$vpos->bindings()->pay($makeBindingPaymentRequest);

$pending = $vpos->reports()->pending(
    new DateTimeImmutable('-1 day'),
    new DateTimeImmutable('now'),
);
```

`bindings()->pay()` is not a silent server-to-server charge: its response
carries `AcsUrl`, `PaReq` and `TermUrl`, a 3-D Secure challenge you must still
put in front of the cardholder.

Only `PaymentType::MainRest` and `PaymentType::BindingMainRest` are accepted by
the binding endpoints; anything else is rejected here, before dispatch, because
the gateway answers an invalid one with an unparseable HTTP 500.

### Not implemented

Three gateway operations have no method on this client, and the omissions are
deliberate rather than pending. Read this section as being about *absence* and
the next one as being about *doubt*: what is listed here does not exist, so
there is nothing to call; what is listed under **Unverified behaviour** is
implemented and shipped, and it is only the confirmation that is missing.

- **`GetTransactionList`** — SOAP-only. `api-surface.json`, the scraped REST
  manifest that is this package's specification of record, contains no endpoint
  returning a full transaction list over a date range, and v1.0 does not ship a
  second transport for a single operation. A merchant who needs it calls the
  SOAP service directly: [`docs/DEVELOPMENT-HISTORY.md`](docs/DEVELOPMENT-HISTORY.md),
  under **What is not implemented, and why**, records a working envelope, the
  `SOAPAction` header that routes it and the XXE hardening it requires, and
  `Environment::reportingSoapUrl()` returns the host for the environment you
  constructed. That document is `export-ignore`d and does not ship in the
  installed package — read it in the repository.
- **`GetProblemTransactions`** — SOAP-only, and `reports()->pending()`
  (`GetPendingTransactions`, REST) is intended to cover this case. Two caveats,
  because that intent is weaker than it sounds. The manifest is
  scraped from the REST Help pages and holds no record of the SOAP surface at
  all, so it cannot establish the equivalence: that is inferred from the
  response shape — `GetPendingTransactions` returns rows carrying a per-row
  `ErrorMessage`, which is the shape of a problem list rather than a complete
  one — and is not read off anything. And `GetPendingTransactions` has itself
  never been called against the sandbox, so its shipped shape is the manifest's
  declaration alone.
- **`SSNCheck`** — unrelated to the payment lifecycle, and it carries Armenian
  national identity data: `SSN` and `IdentifierType`, which this package's
  security rules require be handled exactly as credentials are — never logged,
  never placed in an exception message, never in a URL.
  v1.0 does not take that obligation on for an operation no payment needs.

None of the three is scheduled. If you need one, say so in an issue rather than
reading this section as a roadmap.

### Unverified behaviour

Some of this package is built against a specification the sandbox has never
confirmed. Everything below is implemented — unlike the section above, what is
missing here is a confirmed observation, not a method. Those places are marked
`@todo unverified` in the source and catalogued in full under **What the sandbox
never confirmed** in [`docs/DEVELOPMENT-HISTORY.md`](docs/DEVELOPMENT-HISTORY.md),
which is `export-ignore`d and does not ship in the installed package — read it in
the repository. The ones most likely to matter to a merchant:

- **The bindings endpoints and `ConfirmPayment` have never succeeded.** They are
  not permitted on the sandbox client this package was built against, so
  everything in the previous section follows the API manifest and no observed
  response.
- **No real decline has ever been seen.** Two payments have completed and both
  were approved. What the gateway sends when a card is refused — the code, the
  message, the shape of the body — is unobserved, and this package neither
  interprets nor tabulates response codes for exactly that reason.
- **Only `Currency::AMD` has been accepted.** USD, EUR and RUB are transcribed
  from the vendor PDF and have never been sent, let alone accepted. AMD is now
  confirmed in both directions — sent as `"051"` and echoed back as `"051"` —
  which widens the evidence for that one member and the set by nothing.
- **No fractional amount has ever reached the wire.** Every amount in every run
  was whole. The decimal-string encoding this package emits is no longer
  unexercised — `"10.00"`, `"4.00"` and `"3.00"` have each been sent by this
  package as JSON strings and accepted — but every one of those fractions was
  `00`, so what the gateway does with a fractional amount is exactly as unknown
  as before. `Amount` carries integer minor units with an ISO 4217 exponent of 2,
  which is the standards-correct choice rather than an observed one.
- **Neither production host has ever been reached.** Every observation behind
  this package came from the test environment.
- **`paymentPageUrl()` takes an optional third argument**, a `PaymentType` that
  appends `&type=` to select the card form directly. Its only source is a
  third-party package's documentation — not the vendor PDF, not the API
  manifest — and no probe has ever sent it. Until recently no probe *could*:
  the sandbox payment page did not render. It does now, so this is a claim that
  is untested rather than untestable, and settling it needs one payment opened
  twice. It is left out of the examples above until somebody does that. Read
  **What the sandbox never confirmed** before depending on it.
- **How far the `TrxnDescription` substitution reaches is unknown.** The
  behaviour itself is observed and documented under
  [The payment lifecycle](#the-payment-lifecycle): an Armenian `Description`
  comes back with its letters replaced. What is unverified is its extent —
  Russian and every other non-Latin script are untested, `Opaque` has only ever
  carried ASCII, and the cause is an open question with the bank. Treat any
  non-ASCII value you send as something to read back, never to compare.

## API reference

The gateway surface this client targets is documented in
[`docs/api-reference/reference.md`](docs/api-reference/reference.md),
generated from the live vPOS Help pages. That reference — and the
`api-surface.json` manifest beside it — is the specification of record for this
package.

The reference is committed for versioning, but it is `export-ignore`d and does
not ship in the distributed package. Read it in the repository.

## Security

Vulnerabilities must be reported privately, never through a public issue. See
[`SECURITY.md`](SECURITY.md).

The library redacts credentials, card data and personal data from its logs, and
never accepts a switch to disable TLS verification. Know one detail before you
read a log file: a card number is **truncated**, not removed. A value under a
card-number key survives as first-6 and last-4 with the middle masked whenever
it is shaped like a card number, and is replaced outright when it is not,
because a masked PAN is what reconciliation needs and the gateway already sends
that field pre-masked in exactly that shape. Every
other sensitive value is replaced outright — passwords, the approval code, the
expiry date, and the two fields that identify a **person** rather than a card:
`ClientName` holds the cardholder's own name, not the merchant's, and
`ProcessingIP` holds the address the cardholder paid from, not your server's
(CONVENTIONS.md §4.20).

### Exceptions serialize with their sensitive state scrubbed

Every exception this package throws defines `__serialize()`, so one can be put in
a queue payload, a cache entry or a failed-job record without taking anything
with it that does not belong there. Serializing never fails, including on a
routine decline.

What survives a round trip:

- `getMessage()`, and the file and line the failure was thrown from.
- The stack trace reduced to its call path — file, line, function, class, type.
  The arguments are dropped: a frame inside the transport holds the request body,
  and the request body holds your password.
- Each exception's own fields: `operation()` wherever it is declared,
  `responseCode()` and `responseMessage()` on an `ApiException`, `paymentId()` on
  an `IndeterminateStateException`, `statusCode()` and `faultMessage()` on a
  `GatewayFaultException`, `causedByJson()` on a `SerializationException`.

A response code keeps the type it arrived with. The gateway answers `int` from
`InitPayment` and `string` everywhere else, so failure code 20 is `20` from one
endpoint and `"20"` from another; a restored exception reports whichever it was.
Compare with `===` and it will still be right.

What does not survive is the `previous` chain. The cause of a transport failure
is the PSR-18 client's own exception, and a PSR-18 exception hands back the
request it was sent — body, credentials and all — so it is dropped rather than
carried into a store this package cannot see. That is recorded rather than
hidden:

```php
$restored = unserialize($stored);

$restored->getPrevious();    // always null after a round trip
$restored->chainDropped();   // true, false, or null
```

- `true` — there was a cause and it was removed in transit.
- `false` — there was no cause to begin with.
- `null` — this object has not been through a round trip at all, so
  `getPrevious()` is intact and authoritative.

`chainDropped()` is declared on `VposExceptionInterface`, so a worker that
catches the one interface can ask without narrowing first. A restored exception
is still throwable and still caught by its own class.

## Contributing

All checks must pass before a pull request is merged:

| Command | Checks |
| --- | --- |
| `composer test` | PHPUnit |
| `composer stan` | PHPStan level 10 |
| `composer cs:check` | PHP-CS-Fixer, PER-CS 2.0 |
| `composer rector` | Rector, dry run |
| `composer validate --strict` | Composer manifest |
| `composer normalize --dry-run` | Manifest formatting |
| `composer coverage:check` | Line coverage 100% on `src/` — PHP 8.3 / highest dependencies only |
| `composer infection` | Infection, MSI 100 on `src/` — PHP 8.3 / highest dependencies only |

Verify against the supported floor, not your default interpreter. Composer
resolves script binaries through `PATH`, so prepend the PHP 8.3 bindir:

    PATH="/path/to/php@8.3/bin:$PATH" composer test

Mutation testing requires a coverage driver. `php-code-coverage` 12 supports
only pcov and Xdebug — the phpdbg driver was removed upstream — and pcov is
considerably faster:

    pecl install pcov

On Homebrew PHP for macOS that bare command fails to compile with
`fatal error: 'pcre2.h' file not found`, because Homebrew's
`php-config --includes` omits the `pcre2` headers its own `php_pcre.h`
includes. Point the compiler at them:

    CPPFLAGS="-I$(brew --prefix pcre2)/include" pecl install pcov

Verify with `php -m | grep pcov`. `composer infection` enforces a mutation score
of 100 on `src/` — the floor is what the suite actually holds, because a floor
below the tree's real score is a silent regression budget. It runs in CI on the
PHP 8.3 / highest-dependencies job only.

`composer coverage:check` and `composer infection` therefore gate a pull request,
but on that single matrix job; the other five run the test suite with no coverage
driver. The two answer different questions and neither substitutes for the other:
Infection generates no mutants for uncovered code, so a mutation score alone
cannot see an untested class at all. `composer bc` is
not part of the gate at all — the backward-compatibility check needs a prior
release tag to diff against, and nothing has been tagged yet.

Bootstrap with `composer install`. `composer cs` applies the fixes that
`composer cs:check` reports.

CI splits the table across two workflows: the static gates — `validate`,
`normalize`, `stan`, `cs:check` and `rector` — run once on PHP 8.3, in an order
of their own; the table is a checklist, not a sequence. `composer test` runs
separately across the matrix PHP 8.3 / 8.4 / 8.5 against lowest and highest
resolvable dependencies, and `composer infection` runs on the 8.3 / highest cell
alone. `normalize` is a plugin command rather than a `composer.json` script, so
it is easy to miss after hand-editing `composer.json` — run it before opening a
pull request.

## Changelog

See [`CHANGELOG.md`](CHANGELOG.md).

## License

MIT. See [`LICENSE`](LICENSE).
