# Conventions

Engineering reference for `davit-vardanyan/ameriabank-vpos-php`.

This is an **unofficial** client. It is not affiliated with, endorsed by, or
supported by Ameriabank.

Spell the bank **Ameriabank** — one word, lowercase `b`, in code, docs and
commit messages alike. Never split it into two words and never capitalise the
`b`; the vendor documentation does both, inconsistently, and is not a model.

The root namespace is `DavitVardanyan\AmeriabankVpos\`, never `Ameriabank\`.
Rooting at the bank's name squats the brand and would collide if the bank ever
published its own package.

---

## About this document

This is the normative reference for how the Ameriabank vPOS gateway behaves,
how this package is shaped in response, and which of the behaviours it is
written against have actually been observed rather than merely documented.

Source files and tests throughout this package cite it by section — `§4.8`,
`§13`, and so on. Those citations are load-bearing: a `@todo unverified` marker
in `src/` points at a specific entry in §13, and a comment explaining why a
misspelled wire field is reproduced verbatim points at §4.8.

**The numbering is inherited and deliberately non-contiguous.** Sections 1, 3,
9 and 12 are absent because they cover repository identity, pinned tool
versions, local build commands and internal issue-tracking process — none of
which a consumer or contributor of this package needs, and none of which is
cited from the shipped tree. The remaining numbers are the ones hundreds of
in-code citations name. **Do not renumber to close the gaps.** Renumbering
silently invalidates every one of those citations: they would still point at a
section that exists, and it would be the wrong one.

---

## 2. Source of truth

Authority order. Higher wins on conflict, always.

1. **`docs/api-reference/api-surface.json`** — scraped from the live ASP.NET
   Help pages, which are reflected from the bank's actual C# models. This is
   the specification of record.
2. **Sandbox probe observations** — empirical runtime results recorded during
   discovery against the test environment. These beat the manifest for runtime
   *values*; the manifest only gives declared types.
3. **The vendor PDF** (`Ameriabank vPOS`, English, 3.1) — background only.
   **Not authoritative.**

Two notes on what a reader can actually inspect:

- The manifest is committed to the repository, so anyone with a clone can read
  it and re-derive any claim made from it. It is `export-ignore`d, so it is
  **not** present in a Composer installation. Code that depends on the
  manifest's content therefore encodes that content directly rather than
  reading the file at runtime; only tests read the file.
- The probe scripts and their captured reports are **not** part of the
  published repository. Probe case identifiers appear throughout §13 as labels
  for specific observations, and the narrative in `docs/DEVELOPMENT-HISTORY.md`
  covers the same ground for a repository reader, but neither is something a
  consumer installing from Packagist can consult. Where §13 says a thing was
  observed once, that is a statement about the record, not an invitation to go
  and check it.
- The vendor PDF is not redistributed. It is the bank's document, received as
  an email attachment, and it is not ours to ship.

The PDF is wrong about endpoint names, field types, enum values, validation
behaviour, and the SOAP envelope. It is not a valid justification for any
implementation choice that contradicts the manifest.

The manifest was generated once and is not refreshed automatically. If the
upstream API is suspected to have moved, the Help pages must be re-scraped by
hand and the diff reviewed. A stale manifest is not a reason to fall back on
the PDF.

---

## 4. Verified API behaviour

Every item in this section was observed on the live sandbox unless the text
says otherwise. Treat it as fact about the gateway.

### 4.1 HTTP status carries no business meaning

Authentication failure returns **HTTP 200** with `ResponseCode` 20. The
transport must never branch on status code to decide success.

### 4.2 HTTP 500 is a semantic response

The body is `{"Message":"An error has occurred."}` — ASP.NET's
unhandled-exception page. It has been observed for well-formed requests:

- `GetPaymentDetails` on an order that was registered but never attempted
- `GetBindings` with `PaymentType` outside `{5, 6}`

Consequences, all mandatory:

- **Never retry on 5xx.** It is indistinguishable from a client input error.
- **Never map 5xx to a transport exception.** It is not a transport fault.
- Validate `PaymentType` client-side before dispatch, since an invalid value
  produces an unparseable 500 rather than a structured error.

### 4.3 `ResponseCode` type varies by endpoint

| Endpoint | Type | Success |
|---|---|---|
| `InitPayment` | `int` | `1` |
| All others | `string` | `"00"` |

Failure code 20 appears as `int 20` from `InitPayment` and `string "20"`
elsewhere. `ResponseCode` is therefore a value object accepting `int|string`,
never a backed enum — the code table has around 60 entries in formats like
`00`, `0-1`, `0100`, `0151017` and `514`, and the bank adds more without
notice.

Both success forms have now been seen on the wire, rather than only declared.
`int 1` came back from `InitPayment` on P1, and `string "00"` came back six
times: from `GetPaymentDetails` on P3, P4.1b, P4.3b and P6, and from
`RefundPayment` on P4.1 and P4.3. Until that run the `"00"` row rested on vendor
documentation alone. §4.24's run added a **third** endpoint to the row rather
than a larger number to it: `GetPaymentId` answered `"00"` on L6.1. The row
still reads "all others" on the strength of the endpoints actually seen, and
most of the eleven this package ships have never answered `"00"` at all.

Unknown codes must never throw.

### 4.4 `InitPayment` is idempotent but not immutable

Keyed on `(ClientID, OrderID)`. A repeat call returns the **same** `PaymentID`.
But the later call's parameters **overwrite** the earlier ones — verified via
the `Opaque` value echoed in the callback.

Therefore `InitPayment` may be retried **only with a byte-identical request
body**. The package serialises once and reuses the exact payload across
attempts. Never rebuild the body between retries.

### 4.5 Retry policy

| Operation | Retry | Reason |
|---|---|---|
| `InitPayment` | Yes, identical body only | Idempotent by OrderID |
| `GetPaymentDetails` | Yes | Read-only |
| `GetPaymentId` | Yes | Read-only |
| `GetBindings` | Yes | Read-only |
| `GetPendingTransactions` | Yes | Read-only |
| `GetTransactionList` (SOAP) | Yes | Read-only |
| `ConfirmPayment` | **Never** | Captures funds |
| `RefundPayment` | **Never** | Moves money |
| `CancelPayment` | **Never** | State-changing |
| `MakeBindingPayment` | **Never** | Charges a card |

This is encoded as `isIdempotent(): bool` on each request object. It is **not
user configurable.** On timeout for a non-idempotent operation the package
throws `IndeterminateStateException`, instructing the caller to reconcile via
`GetPaymentDetails` — never guess, never auto-retry.

### 4.6 `PaymentsEnum` — 13 members

```
None=0  Arca=1  MasterCard=2  Visa=3  Reward=4  MainRest=5
BindingMainRest=6  PayPal=7  PayX=11  MirCard=12
ApplePay=13  EPGCardApplePay=14  Amex=17
```

The PDF's "5/6/7" and the SOAP documentation's "1/3/5/6" are partial views of
this one enum. There is no conflict between them.

The gaps at 8, 9, 10, 15 and 16 will be filled by the bank. **Never call
`PaymentsEnum::from()` on wire data.** Every DTO carries both the raw value and
a nullable enum:

```php
public string $orderStatusRaw,
public ?OrderStatus $orderStatus,   // null = a value the package does not yet know
```

`GetBindings`, `ActivateBinding` and `DeactivateBinding` accept only `5` and
`6`. Validate client-side to that set — not to "any valid enum member".

### 4.7 Amount encoding

The .NET deserialiser accepts `"10"`, `"10.00"`, `"10.0"`, `10` and `10.0`.

**Always serialise `Amount` as a decimal string.** A PHP float must never reach
the wire. `Amount` is a value object holding integer minor units plus a
`Currency`; `Currency::exponent()` returns its scale — the ISO 4217 exponent,
which is 2 for all four supported currencies.

AMD is subdivided into 100 luma. Luma are obsolete in circulation and Armenian
prices are quoted in whole drams, but the internal representation follows ISO,
not local custom: a zero exponent would make sub-dram amounts unrepresentable
and would misread every stored amount by 100×. Fractional AMD is unverified on
the wire — the probe case that would have settled it was rejected by the
sandbox's blanket 10-AMD rule, not on precision. See §13. There is no
`ext-bcmath` dependency; integer arithmetic only.

The completed payment described in §4.14 does not settle precision, and does not
settle this subsection at all. Every amount that run sent was whole — 10, then
refunds of 4 and 3, then an attempted 10 — and each was sent as a JSON
**integer**, alongside an integer `OrderID`. Read that run as re-confirming the
deserialiser's `10` arm and nothing further.

**The bytes this package writes have now been put to the gateway, and were
accepted.** This paragraph used to say the opposite; §4.24's run falsified it
three times over. Each of these requests was serialised by this package itself:

| Case | On the wire | Answer |
|---|---|---|
| L1 | `InitPayment` — `"Amount":"10.00"` | `ResponseCode` `1`, `"OK"` |
| L4.1 | `RefundPayment` — `"Amount":"4.00"` | `"00"`, `"Success"` |
| L4.3 | `RefundPayment` — `"Amount":"3.00"` | `"00"`, `"Success"` |

All three are JSON **strings**, hex-confirmed by the enclosing `22` quotes in the
captured request body, where every earlier request on record sent a JSON
integer. The deserialiser's quoted-decimal arm was documentation-sourced until
that run; it is now observed. Two further details of the same bodies: `OrderID`
went as a bare JSON integer beside the quoted `Amount`, so the mixed encoding
this package emits is accepted exactly as it stands; and
`JSON_UNESCAPED_SLASHES` is now confirmed on real bytes, the `BackURL` appearing
as `http://…` with no `\/` anywhere.

**That settles the encoding, not the precision.** All three fractions were `.00`.
No fractional amount has ever reached the gateway in either direction, and
§13's amount-precision entry is untouched by the paragraph above — it must not
be read as narrowing it.

`Currency` is carried on the wire as an ISO 4217 **numeric** string. Only
`"051"` (AMD) is confirmed accepted, and the evidence is stated as a sweep
rather than as a count: **every request this project has ever sent that carried
a currency at all carried `"051"`, save exactly one** — across probe phases A, B
and C, the payment-page run, and §4.24's run through this package, every
success-coded request carried that value and no other. The one exception carried
`"840"`, and it was rejected with `ResponseCode` 560 by the sandbox's blanket
10-AMD amount rule, which fires on the amount and says nothing about currency
handling. `"978"` and `"643"` have never been sent at all. USD, EUR and RUB are
therefore all PDF-sourced and unverified.

A count is deliberately not given. This sentence used to name one, and the
number was correct on the day it was written and then read as the whole record
the moment another run added requests to it — the §7 failure mode, in a
different place. Derive it from the probe record if it is ever needed; do not
restate it here.

No probe has ever sent an alpha code, so "numeric, not alpha" is an inference
from the one accepted value, not an observation of a rejected alternative. A
response body **has** carried it back, though: `GetPaymentDetails` returned
`"Currency":"051"` on P3, P4.1b, P4.3b and P6. The encoding claim therefore
rests on request acceptance *and* a response echo, rather than on acceptance
alone. Earlier bodies that carried `"Currency":""` were `ResponseCode` `"550"`
lookups against an order that never went through — a blank is what that shape
carries, not what the field always carries.

None of this widens the set by one member. `"051"` is still the only currency
ever sent in either direction, so USD, EUR and RUB remain vendor-documented and
unverified.

JSON flags on every request:

```php
JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION
| JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
```

`JSON_UNESCAPED_UNICODE` is required, and stays required. L1 captured the
outgoing body carrying raw UTF-8 Armenian — the Armenian codepoints appear
literally in the bytes, with no `\u` sequence anywhere in the body — and the
gateway accepted that request with `ResponseCode` `1`, `"OK"`. Removing or
softening this flag would be a regression, not a correction.

What was wrong is the **reason** this section used to give. It read "Armenian
`Description` values round-trip correctly and must not be escaped". They do not
round-trip. Armenian text sent in `Description` returns from `TrxnDescription`
with each non-Latin codepoint replaced by U+00BF; the codepoint count and the
ASCII prefix are preserved (L1 and L3, four separate reads of one payment).
Only Armenian has been tested, only in `Description` read back through
`TrxnDescription`, and only on the test environment. §4.15 states what a
consumer must do about it; §13 records its status with the bank.

Where the wrong reason came from is worth recording, because it is an easy trap
to walk back into. One earlier probe had sent an Armenian `Description`, and it
read the gateway's *acceptance* of the value — it never read the value back.
The one later call that queried that payment answered with the HTTP 500 fault,
so no `TrxnDescription` ever came back from that order at all. P1 sent Latin
text, so P3's `TrxnDescription` echo (§4.15) was Latin only. §4.24's run is the
first time an Armenian `Description` was ever read back. **Acceptance is not a
round trip.**

### 4.8 Field spellings are load-bearing

Reproduce these verbatim. **Never "correct" them.** They are the wire format.

| Spelling | Location |
|---|---|
| `CardBindingFileds` | `GetBindingsResponse` |
| `IsAvtive` | `CardBindingFiled` |
| `resposneCode` | BackURL query parameter |
| `rrn` | `PaymentDetailsResponse` (lowercase) |
| `PaymentId` | `GetPaymentIdResponse` only — every other model uses `PaymentID`; confirmed on the wire on L6.1 |
| `OrderId` | `GetPendingTransactionsResponse` only — every other model uses `OrderID` |
| `MerchantId` | `PaymentDetailsResponse`, `MakeBindingPaymentResponse` — lowercase `d`, confirmed on the wire on P3 |
| `TerminalId` | `PaymentDetailsResponse`, `MakeBindingPaymentResponse` — lowercase `d`, confirmed on the wire on P3 |

PHP property names may be idiomatic (`isActive`, `cardBindings`). The wire
mapping must be explicit and exact. This is why hydration is hand-written and
`symfony/serializer` is not used.

The **case of a value** is load-bearing for the same reason, and it is a
separate hazard from the spelling of a key: one and the same `PaymentID` came
back uppercase from `InitPayment` and lowercase in the callback (§4.12). This
package normalises neither.

### 4.9 Endpoint names are singular

`ActivateBinding`, `DeactivateBinding`. The plural forms in the PDF's table of
contents return 404.

### 4.10 The BackURL callback is unsigned

Parameters observed:

```
orderID, resposneCode, paymentID, opaque, description
```

`description` is undocumented. It carries error text on a failure and approval
text on a success — **both** successful callbacks on record, P2 (the first ever
captured) and L2, delivered `Operation Approved `, **with a trailing space**,
which this package hands back verbatim rather than trimming. L2 arrived
byte-identical to P2, trailing space included, on a different payment and a
different order, so the space is not a one-off oddity of a single redirect that a
later call might tidy up. Two observations is still two, but they agree. There is
no HMAC, no signature and no shared secret. **Anyone can forge
`resposneCode=00`.**

P2 confirmed all five key spellings against a *successful* callback rather than a
failed one, `resposneCode` included. L2 carried the same five under the same
spellings, and for the §13 entry that tracks this exposure it is the stronger of
the two: P2 was a capture read by hand, whereas L2's query string went through
`VposCallback::fromQuery()`, so it is the pinned literal-key matching in this
package — not a reader — that was confronted with real gateway output and
accepted it. Both carried `paymentID` in lowercase (§4.12).

The public API is designed so that verification is the only ergonomic path:

```php
$callback = VposCallback::fromQuery($_GET);      // unverified marker type
$details  = $vpos->verify($callback);            // server-side round trip, order pinned
```

`VposCallback` exposes **no** success accessor. The only way to learn the
outcome is the server-side round trip.

### 4.11 SOAP transport

Reporting endpoints only (`GetTransactionList`, `GetProblemTransactions`).

Working configuration:

```
POST  https://testpayments.ameriabank.am/Admin/webservice/TransactionsInformationService.svc
Content-Type: text/xml; charset=utf-8
SOAPAction: "http://payments.ameriabank.am/ITransactionsInformationService/ITransactionsInformationService/{Operation}"

<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/">
  <s:Header />
  <s:Body>
    <{Operation} xmlns="http://payments.ameriabank.am/ITransactionsInformationService">
```

- **The PDF's envelope is wrong.** Its `<Action s:mustUnderstand="1">` header
  element causes HTTP 400. Omit it entirely; the `SOAPAction` HTTP header is
  what routes the call.
- SOAP 1.2 returns 415. `basicHttpBinding`, SOAP 1.1 only.
- An empty `SOAPAction` returns a 500 `ActionNotSupported` fault.
- The response namespace is `payments.ameriabank.am/TransactionFields` — **no
  URI scheme**. XPath must match it literally.
- Latency is roughly 1000 ms cold and 390 ms warm. Use a longer timeout than
  for REST.
- Parse with `DOMDocument` + `DOMXPath` under `LIBXML_NONET`, with entity
  loading disabled. XXE hardening is mandatory.

There is no `ext-soap` dependency. Envelopes are hand-built over the same
PSR-18 transport so they share logging, redaction and timeouts. See §13 for why
no SOAP operation ships in v1.0.

### 4.12 Other confirmed behaviour

- `Currency` is optional; the server defaults it.
- `Timeout` is **not validated** server-side — 1201, 0 and −1 are all accepted.
  The package validates `1..1200` client-side and throws `ValidationException`.
- Unknown request fields are silently ignored.
- The **request** field `Description` accepts Armenian UTF-8 and at least 1000
  characters. The ceiling is unknown; do not assume one. That is a finding about
  the field a merchant sends. The response field of the same name is a different
  thing and does not carry the merchant's text — see §4.15.
- `Accept: application/xml` is honoured. `<CardBindingFileds />` self-closes
  when empty — an XML parser must not read that as absent.
- `PaymentID` is a 36-character GUID, and its **case depends on which channel
  delivers it**. Three channels are on record. `InitPayment` returns it
  **uppercase** (P1, L1); the BackURL callback echoes the same identifier
  **lowercase** (P2, L2); and `GetPaymentId` returns it **lowercase** as well
  (L6.1), siding with the callback rather than with `InitPayment`. This package
  normalises none of the three (§4.8), so comparing two of them case-sensitively
  produces a mismatch that is not one. **`GetPaymentDetails` accepts either
  form:** it was given the uppercase form on all ten calls the earlier record
  holds, and the lowercase form on L3, where it answered HTTP 200,
  `ResponseCode` `"00"` and the fully populated body of the payment asked for.
  So the gateway **accepts the lowercase form** for a payment `InitPayment`
  issued in uppercase, and the exposure §13 used to carry against
  `Vpos::verify()` does not exist. Neither end normalised anything to achieve
  that. Read that as the observation and not as a mechanism: only the two forms
  the gateway itself issues have ever been sent, a mixed-case `PaymentID` never
  has, and nothing here establishes case folding as a property of the endpoint.
  `PaymentID` is an empty string, never null, on failure.
- `PaymentDetailsResponse.OrderID`, `.OrderStatus`, `.PrimaryRC`, `.ActionCode`
  and `.ResponseCode` are all **`string`**, contradicting the PDF — declared so
  in the API surface manifest and observed so on P3.
- `MakeBindingPaymentResponse` returns `AcsUrl`, `PaReq` and `TermUrl` — a
  3-D Secure challenge triple. Binding payments are not a silent
  server-to-server charge.
- `GetPendingTransactions.StartDate` / `.EndDate` are typed `date`, not
  `string`. Verify the wire format before "fixing" a DTO format.

### 4.13 Environments

| | REST base | SOAP |
|---|---|---|
| Test | `https://servicestest.ameriabank.am/VPOS/` | `https://testpayments.ameriabank.am/Admin/webservice/TransactionsInformationService.svc` |
| Production | `https://services.ameriabank.am/VPOS/` | `https://payments.ameriabank.am/Admin/webservice/TransactionsInformationService.svc` |

REST operations hang off the base as `{base}api/VPOS/{Operation}` — so
`InitPayment` on the test environment is
`https://servicestest.ameriabank.am/VPOS/api/VPOS/InitPayment`.

Payment page: `{base}Payments/Pay?id={PaymentID}&lang={am|ru|en}`, with an
optional `&type={PaymentsEnum}`. Read §13 before depending on `type`.

Neither production host has ever been contacted. See §13.

### 4.14 A payment has completed end to end

On 2026-08-26 the sandbox carried a payment through its whole lifecycle for the
first time. The run produced ten records, and the rest of this section cites
them by case id:

| Case | Operation |
|---|---|
| P1 | `InitPayment` — HTTP 200, `ResponseCode` `1`, `"OK"` |
| P2 | the BackURL callback — `resposneCode=00` |
| P3 | `GetPaymentDetails` — a fully populated body |
| P4.1 / P4.3 | two partial refunds — `"00"`, `"Success"` |
| P4.1b / P4.3b | `GetPaymentDetails` after each refund |
| P4.5 | a refund larger than the remaining balance — refused |
| P5 | `CancelPayment` on a refunded payment — refused |
| P6 | `GetPaymentDetails`, final state |

Everything in §4.15 through §4.22 comes from that run. The payment page — which
had never rendered before, and whose failure was the root of most of what §13
used to say — rendered; a real card was charged; P2 is the callback it produced.

Read its reach exactly, because it is narrower than "the gateway is verified". It
is **one** payment, on **one** sandbox client, in AMD, by card, approved, then
partially refunded twice. §4.23 states what it did not establish, and §13 keeps
every question it did not answer.

### 4.15 `TrxnDescription` carries the merchant's text; `Description` carries the processor's

P1 sent `Description: "Probe order 4565037"`. P3 returned that string in
**`TrxnDescription`**, and returned `Description: "Approved. - Payment post
authorized"` — which became `"Approved. - Refunded payment back to client card"`
on P4.1b, P4.3b and P6 once the refunds had run.

The gateway therefore overwrites `Description` with its own processor text and
echoes the submitted value in `TrxnDescription`. The manifest declares both as
`string` and carries no semantics for either; the vendor PDF calls both
"description of the transaction", which is no help at all.

This is the finding most likely to mislead: the symmetry a caller expects is
false. **Set `Description` on the request, read `trxnDescription` back off the
response.** `$details->description` is the gateway talking, not you.

`Description` is the diagnostic channel in both directions — the same field
carried `"System Error"` on the earlier failure bodies. `GetPaymentDetails`
carries no `ResponseMessage` key at all on any body ever observed, which is why
`PaymentDetailsResponse` has no such property: for that one endpoint the
diagnostic text arrives in `Description`. The callback's `description` (§4.10)
is a third, unrelated field.

**Armenian text does not survive that trip.** L1 sent a `Description` whose
ASCII prefix was followed by seven Armenian codepoints. L3 returned it in
`TrxnDescription` with **each non-Latin codepoint replaced by U+00BF**, the
codepoint count and the ASCII prefix preserved byte for byte. Four reads of the
same payment, spanning a 40-minute window and two refunds, returned it
byte-identically. Both directions were success-coded — `1`/`"OK"` on the
request, `"00"` on every read — so nothing on the wire reports that the value
changed, and the substitution is lossy per codepoint, so the original cannot be
reconstructed from the response.

State the scope exactly, because the temptation to generalise is strong and
nothing supports it. What was tested is **Armenian**, in **`Description`**, read
back through **`TrxnDescription`**, on the **test** environment. Russian and
every other script are untested. `Opaque` is untested — it came back
byte-identical on L3, but its value was ASCII. `ClientName` came back Latin
because the test card carries a Latin holder name, which says nothing about this
behaviour. Nothing here is a claim about non-Latin text in general, and nothing
here is a claim about how or where the gateway holds the value: this records
what was sent and what came back.

The consequence for a consumer is immediate: **a non-ASCII `Description` is not
a reconciliation key.** §13 carries the entry, and the question is with the bank.

### 4.16 Three amount fields, and which one is stable

Across P3, P4.1b and P4.3b/P6 — a payment of 10, refunded by 4 and then by 3:

| Field | P3 | P4.1b | P4.3b / P6 |
|---|---|---|---|
| `ApprovedAmount` | 10.0 | 10.0 | 10.0 |
| `DepositedAmount` | 10.0 | 6.0 | 3.0 |
| `RefundedAmount` | 0.0 | 4.0 | 7.0 |

`RefundedAmount` **accumulates** across partial refunds. `DepositedAmount`
**decrements**: it is the remaining refundable balance, not the captured total.
`ApprovedAmount` is the authorised total and does not move.

The practical consequence for a merchant is a correctness one. Compare
`approvedAmountRaw` against the amount you asked for — not `depositedAmountRaw`,
which is correct before a refund and wrong after one.

`Approved = Deposited + Refunded` held on every body in the run. That settles a
question this package had deferred: **`Amount` carries no arithmetic, and needs
none.** The gateway publishes all three quantities directly, so a local
subtraction helper would be redundant as well as a place for a rounding error to
live. The question is closed, not merely postponed.

One nuance, so the typed accessors are not over-claimed: a populated `Currency`
(§4.7) is necessary for a typed `Amount` but not sufficient. The constructor
refuses a zero, so on P3 `refundedAmount` is `null` while `refundedAmountRaw` is
`"0.0"`. The raw companion beside every typed amount is the field you can always
read.

All four decimal fields arrived as JSON floats. `ExchangeRate` was `0.0` on every
body, which is why a rate is not modelled as an `Amount`: a rate carries no
currency, and `0.0` would have made a nonsense value object out of it.

### 4.17 `"07"` is overloaded, and success messages differ by endpoint

P4.5 asked to refund more than the remaining balance and got `ResponseCode`
`"07"` with `"Refund amount exceeds deposited amount"`. P5 asked to cancel an
already-refunded payment and got `ResponseCode` `"07"` with `"Reversal is
impossible for current transaction state"`. Two unrelated conditions under one
code, told apart only by `ResponseMessage`.

Two consequences, both of them existing design decisions that this run turns
from argument into evidence:

- **No response code is mapped to a specific exception subclass.** A generic
  `ApiException` carrying the raw code and the gateway's own message is what a
  caller gets, and what a caller should branch on.
- **This package ships no code-to-description table.** The vendor PDF calls `07`
  "System Error". A transcribed table would have printed "System Error" over the
  top of two accurate messages the gateway had already sent.

The success `ResponseMessage` also varies by endpoint, in the same shape as
§4.3's `ResponseCode` type split — and three endpoints now give three different
answers. `InitPayment` answers `"OK"` (P1, L1); `RefundPayment` answers
`"Success"` (P4.1, P4.3, L4.1, L4.3); and `GetPaymentId` answers the **empty
string** — L6.1 returned `ResponseCode` `"00"` with `"ResponseMessage":""` on a
call that succeeded and returned a `PaymentId`. Any local table would have had
to invent two of the three, and the third is one no table would have guessed.
Read `responseMessage`; do not assume a word, and never require a non-empty one
as evidence of success.

`RefundPayment` and `CancelPayment` echo `Opaque` on both success and refusal
(P4.1, P4.3, P4.5, P5), so an `Opaque` value is observed surviving the request,
`GetPaymentDetails`, the callback, and the write responses. §4.24's run carried
a single value through every one of those on one payment, byte-identical each
time: sent on the `InitPayment` request (L1), read back off the callback (L2),
off `GetPaymentDetails` (L3), and off the `RefundPayment` responses (L4.1,
L4.3).

The channels are named rather than counted, and the payment page is why. A
browser opened the page between L1 and L2, and the value came out the other side
intact — but nothing read `Opaque` *at* the page, so the page is a hop the value
survived and not a channel it was observed on. Counting it would have made this
paragraph claim an observation nobody made, which is the same trap §4.7's
currency count fell into. That value was ASCII, so it says nothing about
§4.15's substitution.

### 4.18 `OrderStatus` and `PaymentState` are two fields, not two forms of one

This section used to ask whether `OrderStatus` arrives as `"2"` or as
`"payment_deposited"`. **The question was malformed**, and saying only that it is
answered would lose what was learned. P3 carries `"OrderStatus":"2"` **and**
`"PaymentState":"payment_deposited"` in the same body; P4.1b, P4.3b and P6 carry
`"OrderStatus":"4"` **and** `"PaymentState":"payment_refunded"`. They are two
separate declared fields, both populated, one a numeric string and one a name.
Each hydrates to its own enum.

`OrderStatus` `"4"` and `PaymentState` `payment_refunded` appeared on a
**partially** refunded payment — P4.1b still had `DepositedAmount` 6.0
outstanding. **The refunded status does not mean fully refunded.** A merchant who
reads the status and skips the amounts of §4.16 will get that wrong.

### 4.19 Wire formats and value shapes on `GetPaymentDetails`

All observed on P3 and unchanged on P4.1b, P4.3b and P6. The manifest declares
types, not formats, and the vendor PDF states none of these.

- `DateTime` is `"26/08/2026 17:29:07"` — `d/m/Y H:i:s`. Not ISO 8601, and not
  the `Y/m/d H:i` the reporting endpoints use. This package carries it as text
  and does not parse it. It says nothing about
  `GetPendingTransactions.StartDate`/`.EndDate`, which are request fields on a
  different endpoint that has never been called (§13).
- `ExpDate` arrives as six digits in `Ym` order — a four-digit year followed by
  a two-digit month. The format is recorded here; the observed value is not, and
  must not be. §6 forbids persisting an `ExpDate`, and a document a consumer
  receives is a place it would be persisted. The sibling bullet above states
  `ApprovalCode`'s length rather than its digits for the same reason.
- `rrn` is a UUID and is **byte-identical to `MDOrderID`** on every body — not
  the shape the vendor PDF sampled. Reconciling on `rrn` and on `mdOrderId` is
  reconciling on the same value twice.
- Absent values arrive as **empty strings, never null**: `ClientEmail`,
  `CardHolderID`, `BindingID` and `BankInfo.BankName` were all `""`. Hydration
  passes an empty string through as an empty string, and never converts either
  way.
- `BankInfo` is present and **partially** populated:
  `{"BankName":"","BankCountryCode":"AM","BankCountryName":"Armenia"}`. The
  country pair looks like ISO 3166-1 alpha-2 with its English name, though
  nothing declares that it is. `BankName` in particular is still empty on every
  body ever observed.
- `ApprovalCode` is six characters. This is the first time card data covered by
  §6 has actually existed in this project's evidence: those obligations are now
  live rather than anticipatory.
- `PaymentType` arrives as `int 5` — `MainRest`, as modelled.
- `CardNumber` arrives already masked, twelve characters: first-6, two mask
  characters, last-4. That is shorter than a raw card number, and §6 records
  what the redactor does with it.

### 4.20 `ClientName` and `ProcessingIP` are personal data

`ClientName` holds the **cardholder's** own name, not the merchant's. The field
name invites the opposite reading and the manifest carries no semantics to
correct it; P3 does.

`ProcessingIP` holds the address the payment was made from — the cardholder's,
not the merchant's server's.

Both identify a person, so §6 applies to both: the `Redactor` replaces each
wholesale, on the same footing that already covers `ClientEmail`. Neither is card
or identity data, which is why §6 names them separately rather than adding them
to its never-log list.

### 4.21 A rejected write answers at read speed; a write that moves money does not

Measured across the run. State it this way deliberately — "writes are slower than
reads" is **wrong**:

| Case | Operation | ms |
|---|---|---|
| P1 | `InitPayment` | 61.3 |
| P3 / P4.1b / P4.3b / P6 | `GetPaymentDetails` | 151.4 / 149.5 / 145.0 / 174.1 |
| P4.5 | `RefundPayment`, refused | 102.4 |
| P5 | `CancelPayment`, refused | 102.3 |
| P4.1 | `RefundPayment`, money moved | 517.6 |
| P4.3 | `RefundPayment`, money moved | 909.8 |

On that run both refusals came back faster than every read. The cost is in the
settlement and not in the verb, and that much held again on §4.24's payment: its
two money-moving refunds took 493.2 ms (L4.1) and 527.5 ms (L4.3), an order of
magnitude above every read and every refusal in the same run.

**The ordering did not hold, and must not be restated as a rule.** On §4.24's
error run the two refusals were the *slowest* calls in it — 66.2 ms (L5.1,
`RefundPayment` refused) and 69.3 ms (L5.2, `CancelPayment` refused) — against
reads of 12.9 to 47.2 ms. What moved is read latency: the same endpoint answered
in 169.7 to 216.4 ms during the refunds of L4 and in 12.9 to 24.8 ms during L5,
minutes apart on the same sandbox client. So "a refusal comes back faster than a
read" was true of one run and false of the next. What survives both is the
shape: a refused write costs about what a read costs, and a write that settles
costs several times more.

That matters when choosing the timeout on the HTTP client you inject. The
slowest operations here are exactly the ones §4.5 forbids retrying, so a timeout
short enough to cut one of them off does not buy a retry — it buys an
`IndeterminateStateException` and a reconciliation you have to perform by hand.
These are two sandbox runs and two payments: read them as orders of magnitude,
not as a budget, and read the inverted ordering as the reason that caveat is a
rule of this section rather than decoration.

### 4.22 Nothing the manifest declares was contradicted

Every field the wire returned matched the API surface manifest.
`PaymentDetailsResponse` declares thirty fields and the wire returned exactly
those thirty — no undeclared key appeared on any record, at any endpoint. Every
type matched: the `string` fields as strings, the `decimal number` fields as JSON
floats, `PaymentType` as an integer, `BankInfo` as the declared nested model with
its three declared members.

§2's authority order held against a live payment for the first time. Where this
run corrected something, what it corrected was a **document** — never the
manifest.

### 4.23 What the completed payment did not establish

Stated plainly, so §4.14's reach is not overstated. Each of these remains
entirely unobserved, and each is held by its own §13 entry where one exists:

a real **decline** code — this payment was approved · a **fractional** amount on
the wire · any currency but `"051"` · any binding operation · `ConfirmPayment` ·
`GetPendingTransactions` · either production host · the SOAP surface · the
payment page's `type` parameter, and its `lang` parameter for every value but
`en` · a duplicate-order rejection.

Four items have been struck from that list since it was written, all by §4.24's
run, and they are named here so the deletions are visible rather than silent:
the decimal-string `Amount` encoding this package emits has been sent and
accepted (§4.7) · `GetPaymentId` has been called (§4.12, §4.17) · the one
request `Vpos::verify()` makes has been made, with the callback's lowercase
`PaymentID` (§4.12) · and a payment has completed through a page URL carrying
`lang=en` (§4.24). Everything still listed above is untouched by that run.

### 4.24 A second payment, and the first requests this package ever sent

On 2026-08-27 a second payment went through the whole lifecycle on the test
sandbox, and for the first time every request was built and sent by this
package rather than by a hand-rolled probe. 10 AMD, by card, approved. Cases
from that run are cited throughout this document as **L**n:

| Case | Operation |
|---|---|
| L1 | `InitPayment` — `"Amount":"10.00"`, Armenian `Description` — `ResponseCode` `1`, `"OK"` |
| L2 | the payment page, opened at `lang=en`, and the callback it produced — `resposneCode=00` |
| L3 | `Vpos::verify()` — `GetPaymentDetails` on the callback's **lowercase** `PaymentID` |
| L4.1 / L4.3 | two partial refunds — `"Amount":"4.00"` then `"3.00"`, both `"00"`, `"Success"` |
| L4.1b / L4.3b | `GetPaymentDetails` after each |
| L5.1 | a refund over the remaining balance — refused, `"07"` |
| L5.2 | `CancelPayment` on the refunded payment — refused, `"07"` |
| L5.3 | `GetPaymentDetails` with a **wrong password** — HTTP 200, `"550"` |
| L5.4 | `GetPaymentDetails` on a second order, registered once and never attempted — HTTP 500 fault |
| L6.1 / L6.2 | `GetPaymentId` with correct credentials, then with a wrong password |
| L6.3 | a wrong password against L5.4's payment — the fault again |
| L7 | one operation re-run through a capturing PSR-3 logger, over the real bodies |

Read the reach exactly, as §4.14 requires of its own run. This is one payment,
on the same single sandbox client, in AMD, by card, approved, on the test
environment. What it adds over §4.14 is not a wider surface but a different
question answered: §4.14 established what the gateway does, and this run
established what the gateway does **with the bytes this package emits**. Three
claims in this document did not survive it — §4.7's Armenian round trip, §4.7's
"these bytes have never been sent", and §4.21's latency ordering — and one §13
entry closed. §4.23 still holds for everything neither run reached.

### 4.25 `"550"` is overloaded too, and carries no message at all

L5.3 asked `GetPaymentDetails` about the completed payment with a **wrong
password** and got HTTP 200, `ResponseCode` `"550"`, and a body in which every
string field was empty except `Description`, which carried `"System Error"`.
Three earlier reads of one never-attempted order got the same `"550"` from the
same endpoint with **correct** credentials. So `"550"` is overloaded exactly as
`"07"` is: one code, two unrelated conditions, and §4.17's prohibition on a
code-to-description table covers it for the same reason. A table would have had
to pick one.

`"07"` at least arrived with a `ResponseMessage` that told its two cases apart.
`"550"` does not. The response carries **no `ResponseMessage` key at all**,
which is this endpoint's general shape (§4.15), so
`ApiException::responseMessage()` is the empty string and the message reads:

```
GetPaymentDetails failed with response code 550: (no message)
```

The only diagnostic text the gateway sent is `Description: "System Error"`.
`ApiException` carries the envelope's code and message and nothing else, and on
a failure code the transport throws, so there is no response object for a caller
to read that text off. **Do not read `(no message)` as this package dropping
something the gateway said** — the field was absent — **and do not read `"550"`
as meaning "wrong password".** It means what the gateway said and no more.

One consequence matters for error handling. A wrong password on
`GetPaymentDetails` produces neither `ResponseCode` `20` nor
`AuthenticationException`: it produces this. §13's entry on `"20"` records where
that leaves the exception hierarchy.

### 4.26 The fault pre-empts credential evaluation, and one question stays open

L5.4 asked `GetPaymentDetails` about an order registered by one `InitPayment`
and never attempted, and got HTTP 500 `{"Message":"An error has occurred."}` —
the fault §4.2 records. L6.3 then asked the same question about the same payment
with a **wrong password** and got the **same fault**, not the `"550"` a rejected
credential produced on L5.3. On this endpoint the fault therefore reaches the
response ahead of any credential verdict. The practical rule: a caller can infer
nothing about its credentials from a `GatewayFaultException` — not that they
were accepted, and not that they were rejected. The fault says the gateway would
not answer, and §13 already forbids reading more into it.

L5.4 is also a **fourth** data point on §13's open question about this endpoint,
and it does not resolve it. Three earlier probe cases each asked about an order
registered and never attempted and each got the fault; three reads of one other
such order got `"550"`. L5.4 lands on the fault side.

One difference between the two groups is now visible, and it is recorded as a
**candidate discriminator, not a finding**. Every payment on the fault side was
registered exactly **once**, L5.4's included. The `"550"` side is a single
payment that had been registered **twice**, by two `InitPayment` calls on the
same `OrderID`, and then read three times — three reads of one payment, not
three payments. What makes that a hypothesis rather than a finding is that
nothing has ever tested it: no run has deliberately varied the registration
count and read the outcome, no unknown, foreign or malformed `PaymentID` has
ever been sent to this endpoint, and a sample of four against one admits several
other readings. Do not act on it, and do not restate it as the answer.

### 4.27 What §4.24's run re-confirmed, and one note on the redactor

Marked as corroboration rather than as new findings. Each of these was already
recorded, and each held on a second payment:

- **`rrn` is byte-identical to `MDOrderID`** (§4.19) on every body of §4.24's
  payment, at a value different from P3's. Reconciling on `rrn` and on
  `mdOrderId` is still reconciling on the same value twice.
- **§4.18's warning reproduced.** L4.1b returned `OrderStatus` `"4"` and
  `PaymentState` `payment_refunded` with `DepositedAmount` 6.0 still
  outstanding. The refunded status still does not mean fully refunded.
- **§4.22 held again.** `PaymentDetailsResponse` returned exactly the thirty
  fields the manifest declares — no undeclared key on any body, and the key
  order byte-identical to P3's.
- **§4.16's identity held.** `Approved = Deposited + Refunded` on every body:
  10.0 = 10.0 + 0.0 on L3, 10.0 = 6.0 + 4.0 on L4.1b, 10.0 = 3.0 + 7.0 on
  L4.3b. `Amount` still gets no arithmetic.

One observation about this package rather than about the gateway, recorded here
because §4.19's neighbouring rule reads the other way and the two are easily
confused. §4.19 requires **hydration** to keep an empty string an empty string.
The `Redactor` is not hydration and does the opposite: `ClientEmail`,
`CardHolderID` and `BindingID` arrive `""` on a real body and are replaced
wholesale with the redaction marker (L7), so a log reader cannot tell "absent"
from "present but withheld". That is the fail-safe direction — a redactor that
passed short values through would be a redactor with a length threshold — and it
is an observation, not a defect. §6 governs the redactor and §4.19 governs the
hydrator; they are permitted to differ here, and they must.

---

## 5. Architecture

### Public surface

```php
$vpos = new Vpos(
    credentials: new Credentials('000000', 'placeholder-user', 'placeholder-pass'),
    environment: Environment::Test,   // required; no default
    httpClient: null,                 // null → php-http/discovery
    logger: new NullLogger(),
);

$vpos->payments()->init($initPaymentRequest);              // InitPaymentResponse
$vpos->payments()->details($paymentId);                    // PaymentDetailsResponse
$vpos->payments()->confirm($paymentId, $amount);           // ConfirmPaymentResponse
$vpos->payments()->cancel($paymentId);                     // CancelPaymentResponse
$vpos->payments()->refund($paymentId, $amount);            // RefundPaymentResponse
$vpos->payments()->paymentIdForOrder($orderId);            // GetPaymentIdResponse

$vpos->bindings()->pay($makeBindingPaymentRequest);        // MakeBindingPaymentResponse
$vpos->bindings()->all(PaymentType::BindingMainRest);      // GetBindingsResponse
$vpos->bindings()->activate($cardHolderId, $type);         // ActivateBindingResponse
$vpos->bindings()->deactivate($cardHolderId, $type);       // DeactivateBindingResponse

$vpos->reports()->pending($from, $to);                     // list<GetPendingTransactionsResponse>

$vpos->paymentPageUrl($paymentId, Language::Armenian);     // no API call
$vpos->verify(VposCallback::fromQuery($_GET));             // PaymentDetailsResponse
```

Every method returns a hydrated response DTO, never an array. `reports()->pending()`
returns a list of them, because that operation alone has no `ResponseCode`
envelope to wrap. The binding listing is `all()`, never `list()`.

`GetTransactionList` is SOAP-only and is deliberately absent from this surface;
§13 records that deferral, and records it as blocked on the bank rather than
open.

`verify()` is the only ergonomic route from a BackURL callback to an outcome.
It round-trips `GetPaymentDetails` for the callback's `PaymentID` and throws
`ValidationException` when the response names a different `OrderID`, and again
— under a distinct message — when it names one that is present but blank, since
a blank names no order to check against. A null `OrderID` skips the check,
because a check cannot be made against an absent value. See §4.10 and §13.

Credentials are injected by the transport. **`ClientID`, `Username` and
`Password` must never appear in a request DTO the caller constructs.**

### Transport

The package is PSR-18 abstract: the consumer supplies the HTTP client. Passing
`null` lets `php-http/discovery` find whatever PSR-18 and PSR-17
implementations the consumer already has installed, and a discovery failure
surfaces as `ConfigurationException` — a configuration mistake, not a runtime
one — so it is catchable through this package's own marker interface rather
than escaping as a third-party discovery error.

Guzzle is a development and `suggest` dependency only, never a `require`. So
are `ext-soap` and `symfony/serializer`; see §8.

### Rules

- All classes are `final`, with one exception: `ApiException` is non-final
  because the exception hierarchy *is* its extension point. Its constructor is
  `final`, and every leaf exception is `final`. No other inheritance is
  permitted in `src/`.
- Interfaces only where extension is genuinely intended.
- `@internal` on everything under `Http\` and `Support\`.
- Request and response DTOs are `readonly`, constructed with named arguments.
- Hydration is hand-written. No `symfony/serializer`.
- `declare(strict_types=1);` in every file.

### Exceptions

```
VposExceptionInterface (extends Throwable)
├── ConfigurationException
├── ValidationException
├── TransportException
├── IndeterminateStateException
├── ApiException
│   ├── AuthenticationException
│   ├── DeclinedException
│   └── DuplicateOrderException
├── GatewayFaultException
└── SerializationException
```

Each extends a native SPL class **and** implements the marker interface, so
`catch (VposExceptionInterface)` catches everything from this package and
nothing else.

`IndeterminateStateException` deliberately does **not** extend
`TransportException`. Both arise from a transport failure, but a caller that
catches `TransportException` and retries must not swallow the one case where
retrying may double-charge. Keeping them siblings makes that mistake impossible
to make by accident.

`GatewayFaultException` is a sibling of `ApiException` for the same kind of
reason. An `ApiException` means the gateway gave a business answer and the
answer was no; a fault means it gave no answer at all — the ASP.NET envelope
`{"Message":"An error has occurred."}` carries no `ResponseCode` (§4.2).
`ApiException`'s constructor is final and requires one, so a subclass could
only exist by synthesising a code the gateway never sent, and `responseCode()`
would then publish a fabricated value that no caller could tell from a real
one. Keeping them siblings makes that fabrication impossible to introduce by
accident. It is not a `TransportException` either: the exchange completed, and
a fault is a deliberate refusal that must never be retried.

Exceptions carry primitives only. `ApiException::responseCode()` returns
`int|string` raw, because §4.3 shows the type varies by endpoint. It must not
depend on the `ResponseCode` value object; callers wanting richer behaviour
construct one from the raw value. Mapping a response code to a specific
exception subclass belongs with `ResponseCode`, not in the exception classes.

**Exception messages fall under §6.** Name fields, never values. Response
messages returned by the gateway are permitted — the observed set is diagnostic
text such as `Incorrect Username and Password` — but a raw response body is
not, since a body may carry card data.

---

## 6. Security

Non-negotiable.

- `#[\SensitiveParameter]` on every password, credential, PAN and SSN
  parameter.
- `Credentials` implements `__debugInfo()` and `__serialize()` returning
  redacted values. `var_dump()` on a client must never print a password.
- A `Redactor` runs on every PSR-3 log record: it masks `Password` and
  truncates `CardNumber` to first-6/last-4 even though the API already masks
  it. The gateway's own masked form is twelve characters — first-6, two mask
  characters, last-4 — and the truncation fires on it: a value that arrived
  already masked comes back byte-identical, a raw card number keeps 13 to 19
  characters, and a value shorter than either floor takes the full marker
  instead.
- **TLS verification is not configurable.** There is no `verify => false`
  escape hatch.
- Never log, persist, or place in an exception message: PAN, `ExpDate`,
  `ApprovalCode`, `SSN`. This applies to exception messages, which reach logs,
  and to raw response bodies passed into them.
- Personal data carries the same obligation under a different heading.
  `ClientName` is the cardholder's own name and not the merchant's, and
  `ProcessingIP` is the address the payment was made from (§4.20). They are not
  card or identity data and are not members of the list above, but the
  `Redactor` replaces both wholesale, on the footing that already covers
  `ClientEmail`.
- Never place credentials or personal data in a URL query string.
- BackURL parameters are untrusted input. See §4.10.
- `SSNCheck` is not implemented (§7). If it ever is, `SSN` and
  `IdentifierType` carry Armenian national identity data and must be handled
  exactly as credentials are.
- Security issues are reported privately; `SECURITY.md` carries the disclosure
  address.

---

## 7. Repository layout and distribution

### Where a class belongs

`src/` is deliberately not inventoried here. §5's public-surface block is the
listing of record for what this package exposes, and which directory a class
belongs in follows from a rule rather than from a table:

> `Enum/` holds wire-value types · `Config/` holds configuration · `Http/` and
> `Support/` are `@internal` · everything else is public API.

A rule does not go stale. A per-directory inventory has to be re-edited on
every new directory and every moved class, and one maintained here drifted
repeatedly before it was replaced with this rule.

That rule names a top-level directory, and it does not stop there: a class may
live in a subdirectory of one, **on the condition that every structural guard
over `src/` walks the tree recursively.** The permission and the obligation are
a single rule — nesting is not permitted to a guard that does not recurse.
This matters because the failure it prevents is silent: a guard that stops
seeing a directory does not fail, it stops guarding.

### Tracked, ignored, and shipped

Three tiers, and they are not the same thing:

- **Untracked** — present on a maintainer's disk and in no clone: the local
  operating notes, the vendor PDF (the bank's document, not ours to
  redistribute), and the sandbox discovery probes with their captured reports.
  These never enter the repository, so they can never enter a distribution
  either.
- **Tracked but `export-ignore`d** — in the repository, absent from the
  Composer distribution: `docs/` (including the API reference manifest and the
  development history), `tests/`, `.github/`, and every tool configuration and
  dotfile. The API reference is committed so it can be versioned and diffed,
  but it must not ship.
- **Shipped** — `src/`, `composer.json`, `LICENSE`, `README.md`,
  `CHANGELOG.md`, `SECURITY.md`, and this file.

The consequence worth internalising: a comment in `src/` may cite the manifest
as the reason for a wire mapping, but it must not tell a consumer to go and
read it, because a consumer installing from Packagist does not have it. That is
also why this document restates the gateway's behaviour in full instead of
deferring to `docs/`.

Before any release tag, verify the distribution's contents by listing the
archive Git would produce from `HEAD`. Anything in that listing which a
consumer would not need is a defect.

### Scope of v1.0

**v1.0 ships eleven REST operations**, counted off §5's public-surface block:
`InitPayment`, `GetPaymentDetails`, `ConfirmPayment`, `CancelPayment`,
`RefundPayment`, `GetPaymentId`, `MakeBindingPayment`, `GetBindings`,
`ActivateBinding`, `DeactivateBinding`, `GetPendingTransactions`.

Several of those eleven have never succeeded against the sandbox — bindings and
`ConfirmPayment` are not permitted on the sandbox client, and
`GetPendingTransactions` has never been called at all, both recorded in §13 —
so shipping them is a decision about scope, not a claim about verification.
Read that list as what the package exposes, never as what the gateway has been
observed to do.

**`SSNCheck` is excluded from v1.0.** It exists in the manifest, and the
manifest has no notion of this package's scope, so its absence here is
deliberate rather than an oversight. It is unrelated to the payment lifecycle,
and it carries PII handling obligations: `SSN` and `IdentifierType` are
Armenian national identity data, which §6 already requires be handled as
credentials are. Shipping it would add that obligation to a release that
otherwise carries none.

---

## 8. Never do

- Commit `.env` or any credential. The sandbox's own credentials live outside
  this repository; no test, fixture or example carries a real one.
- Add Guzzle, `ext-soap` or `symfony/serializer` to `require`.
- "Fix" an upstream typo listed in §4.8.
- Retry a non-idempotent operation, or retry on HTTP 5xx.
- Rebuild a request body between retry attempts.
- Use a PHP float — or any other inexact numeric type — for a monetary value.
- Trust BackURL parameters without a `GetPaymentDetails` round trip.
- Call `Enum::from()` on wire data — use `tryFrom()` with a nullable field.
  §4.6 says why.
- Cite the PDF as justification when it conflicts with the API surface
  manifest.
- Use PHP 8.4+ syntax. No property hooks, no asymmetric visibility. The
  supported floor is PHP 8.3.
- Add a second non-final class to `src/`. `ApiException` is the only permitted
  exception to final-by-default; see §5.
- Make `IndeterminateStateException` a subtype of `TransportException`, or
  otherwise arrange for a generic transport catch to swallow it. See §5.

---

## 13. Known limits and unverified behaviour

This section is the reference that every `@todo unverified` marker in `src/`
points at. It records what the sandbox never confirmed, and — for each item —
what the package does in the absence of confirmation and why.

Read it as a statement about the evidence, not as a list of defects in the
package. Several entries describe behaviour that is very probably fine and has
simply never been put to the gateway.

- **Armenian text does not survive `Description` → `TrxnDescription`, and the
  question is with the bank.** L1 sent an Armenian `Description`; L3 and the
  three reads after it returned it in `TrxnDescription` with each non-Latin
  codepoint replaced by U+00BF, the codepoint count and the ASCII prefix
  preserved (§4.15). Both directions were success-coded, so nothing on the wire
  reports the loss, and the substitution is lossy per codepoint, so the original
  cannot be recovered from the response. Scope, exactly: Armenian, in
  `Description`, read back through `TrxnDescription`, on the test environment.
  Russian and every other script are untested, `Opaque` is untested, and
  `ClientName` came back Latin only because the test card carries a Latin holder
  name. Nothing beyond what was sent and what came back has been established or
  is asserted here.

  This has been reported to the bank's vPOS support address, asking the single
  question of whether non-Latin text is expected to survive the round trip or
  whether merchants should treat both fields as ASCII-only. No answer has been
  received. The evidence dossier behind that report is a maintainer-side file
  and is deliberately not in this repository, so — as with the SOAP entry below
  — the report itself is asserted here rather than checkable here; the
  observation above is what a reader of the published package can act on. Until
  there is an answer, **do not use a non-ASCII `Description` as a
  reconciliation key** — and note that this package will not transliterate on
  your behalf to hide the problem, because silently altering a merchant's text
  is worse than reporting that the gateway did.

- **Amount precision is unverified for every currency.** The two probe cases
  that would have settled it — 10.55 AMD and 10.55 USD — both returned
  `ResponseCode` 560, `"In test mode amount must be 10 AMD"`. That is the
  sandbox's blanket amount rule, and it fires before any precision check.
  Neither case says anything about whether the gateway accepts a fractional
  amount. The ISO exponent of 2 in §4.7 is therefore the standards-correct
  choice, not an observed one.

  §4.24's run does not narrow this entry by one step, and §4.7 says so at the
  point a reader might think otherwise: it sent `"10.00"`, `"4.00"` and `"3.00"`
  and the gateway accepted all three, which observes the **encoding** — a quoted
  decimal string — and says nothing about precision, because every fraction was
  `.00`.

- **Bindings and two-step payments are not permitted** on the sandbox client
  that was available. `MakeBindingPayment`, `GetBindings`, `ActivateBinding`,
  `DeactivateBinding` and `ConfirmPayment` could not be verified.

- **Whether a binding endpoint can answer a credential rejection with `"20"` is
  structurally unobservable on that sandbox client.** String `"20"` carries at
  least two unrelated meanings. From `ActivateBinding`, `DeactivateBinding` and
  `GetBindings` it is an **entitlement refusal**, always with `"Client payment
  type BindingMainRest is not available"` — six probe cases across those three
  endpoints. From `GetPaymentId` it is a **credential rejection**, with
  `"Incorrect Username and Password"` (L6.2). The two are separable only by the
  operation, which correlates with them across seven observations rather than
  explaining them, or by the message, which §4.17 forbids this package to
  interpret.

  The cell that would decide it has never been observable. Because the client
  has no binding entitlement, a wrong password has never been sent to a binding
  endpoint at all, so a rule discriminating by operation would rest on a set
  with one empty cell — and would misclassify that cell the moment it filled.
  This package therefore does not discriminate: `ResponseCode` treats **only
  integer `20`** as an authentication failure, which is the form `InitPayment`
  returns (§4.3), and string `"20"` reaches the caller as a plain `ApiException`
  carrying the gateway's own text.

  **Stated plainly, because a caller must not have to discover it from
  behaviour: `AuthenticationException` is currently unreachable outside
  `InitPayment`.** Do not rely on catching it to detect a credential problem on
  any other operation — catch `ApiException` and read `responseCode()` and
  `responseMessage()`, which carries the gateway's own
  `"Incorrect Username and Password"`. Note also that a credential rejection
  does not always answer `20` at all: on `GetPaymentDetails` a wrong password
  produced `"550"` with no message (§4.25). Adding the classification later is
  non-breaking, since `AuthenticationException` extends `ApiException`; removing
  a wrong one would not be. The first test when binding permissions arrive is a
  wrong password against `GetBindings`, and that single observation settles it.

- **The duplicate-order condition has never been reproduced.** The PDF
  attributes response codes `01` and `08204` to a duplicate `OrderID`. Neither
  appears in the API surface manifest, and neither has been seen on the wire: a
  probe case re-registered an `OrderID` that an earlier case had already
  registered, and the gateway answered `ResponseCode` 1, `"OK"`, returning the
  same `PaymentID` the first call had issued — corroborating §4.4's idempotency
  rather than any duplicate error. What actually raises
  `DuplicateOrderException` is therefore unknown.

- **Neither production host has ever been reached.** Every probe ran against
  `servicestest.ameriabank.am` and `testpayments.ameriabank.am`. The production
  REST base `https://services.ameriabank.am/VPOS/` and the production SOAP host
  `https://payments.ameriabank.am/Admin/...` are transcribed from vendor
  documentation and have never returned a byte. That the test pair works is not
  evidence about the production pair: they are different hosts, and a wrong one
  fails at the first live payment rather than in any test.

- **`GetPendingTransactions` has never been called.** The REST probes exercised
  `InitPayment`, `GetPaymentDetails`, `GetBindings`, `ActivateBinding`,
  `DeactivateBinding`, `RefundPayment` and `CancelPayment`, and the SOAP probes
  exercised `GetTransactionList` and `GetProblemTransactions`; this endpoint
  appears in the manifest and in no request any probe ever sent.

  Its success shape is the manifest's declaration alone — rows carrying
  `OrderId`, not the `ResponseCode` envelope every other endpoint returns — and
  its failure shape is unobserved entirely: whether a rejected date range comes
  back as a `ResponseCode`, as the ASP.NET fault envelope, or as an empty list
  is unknown. The transport therefore gives it no special case: a
  `ResponseCode` is branched on, a `Message` without one is a fault, and a body
  with neither is returned as decoded. Inventing a shape for it would be
  inventing a contract.

- **What the ASP.NET fault means for `GetPaymentDetails` is inferred, and the
  inference is already in tension with the record.** Three probe cases each
  queried a `PaymentID` that `InitPayment` had registered and nothing had
  attempted, and each returned HTTP 500 `{"Message":"An error has occurred."}`
  — the pairing §4.2 records. But another case queried a `PaymentID` in the
  same state — registered, re-registered, never sent to the payment page — and
  got HTTP 200 with `ResponseCode` `"550"` and `Description` `"System Error"`:
  a business answer, not a fault.

  Registered-but-unattempted therefore does not predict which of the two the
  gateway returns, and what separates the two groups is unknown. No probe has
  ever sent an unknown, foreign or malformed `PaymentID` to that endpoint
  either, so no competing cause has been ruled out. A `GatewayFaultException`
  from `GetPaymentDetails` means the gateway would not answer, and nothing more
  may be read into it.

  A **third** outcome class now exists, and it is the ordinary one. The probe
  record holds ten calls to this endpoint: three returned the HTTP 500 fault,
  three returned HTTP 200 with `ResponseCode` `"550"`, and four — P3, P4.1b,
  P4.3b and P6 — returned HTTP 200 with `"00"` and a fully populated body
  against the completed payment of §4.14. Do not read 500-or-550 as exhausting
  the space. The original question is untouched by that: those four asked about a
  payment that had gone through, and what separates the two earlier groups, both
  asking about an order registered and never attempted, is still unknown.

  §4.24's run called it again, through this package, and landed in all three
  classes: a populated body on L3, L4.1b and L4.3b, `"550"` on L5.3, and the
  fault on L5.4 and again on L6.3. That leaves the arithmetic above intact as a
  statement about the earlier record and adds a **fourth** registered-but-never-
  attempted payment answering with the fault. §4.26 records what those two new
  cases did establish — that the fault reaches the response ahead of any
  credential verdict — and records the registration-count difference between the
  two groups as a candidate discriminator that nothing has tested. The question
  in this entry stays open.

- **`BankInfo.BankName` has never been populated.** §4.19 settles the wider
  question — the object arrived present on a completed payment, carrying
  `BankCountryCode` `"AM"` and `BankCountryName` `"Armenia"` — and this is the
  part of it that survives. `BankName` itself was `""` on all four of those
  bodies and on every earlier one. Whether the gateway ever fills it, and for
  which issuers, is unknown; the empty string is the only value this package has
  ever seen in that member. Do not read an empty `BankName` as an absent
  `BankInfo`: the object was present every time.

- **The payment page's `type` query parameter is the weakest-sourced claim in
  this package.** `{base}Payments/Pay?id=…&lang=…&type={PaymentsEnum}` is
  claimed to select the card form directly — `13` opening Apple Pay, `5` the
  Visa/MasterCard/ArCa form, and omitting it leaving the gateway to detect the
  device. Its provenance is a third-party Laravel package's documentation. It
  is not in the vendor PDF, which §2 already ranks below the manifest, and it
  is not in the manifest at all — though the manifest describes REST request
  models and the payment page is a browser redirect with no model to describe,
  so absence there is not itself evidence against it.

  What is evidence against it is that nobody has ever tried: no probe has sent
  `type`, and no response has been observed carrying or honouring it. Until
  2026-08-26 nobody could — the sandbox payment page did not render at all.
  **That reason is gone**: §4.14 records a payment completing through the page,
  so the claim is no longer untestable, only untested, and one probe settles it.
  Every other entry here is an observation that came back wrong; this one is a
  claim that has never been put to the gateway.

- **Whether a wrong `type` is harmless is an inference across surfaces, not an
  observation.** §4.12 records that unknown request fields are silently
  ignored, which suggests an unsupported or mistyped `type` changes nothing and
  the payment proceeds with gateway-detected form selection. But that finding
  was made by sending unknown keys in a **JSON request body to the REST API**.
  The payment page is a different surface — an ASP.NET page reading a **query
  string** — and nothing has been observed about how it treats a parameter it
  does not recognise, or a recognised one carrying a `PaymentsEnum` member the
  page has no form for. It could equally render an error page or redirect to
  the BackURL with a response code, which is exactly what that page did — for
  unrelated reasons — throughout the period before §4.14's payment.

  Do not present omitting `type` and passing a wrong one as equally safe.
  Settling this needs two requests against the now-working page: the same
  `PaymentID` opened with `type=13` and with `type` omitted.

- **The payment page's `lang` parameter is exercised for `en` only.** §4.13
  publishes the page as `{base}Payments/Pay?id=…&lang={am|ru|en}`, and
  `Language` encodes exactly those three spellings. L2 opened the page at a URL
  `paymentPageUrl()` produced carrying `lang=en`, the card form rendered, a card
  was charged, and the callback came back `resposneCode=00` — so that one
  spelling is accepted in the only sense that matters. Read that narrowly: what
  the page rendered *in* was not recorded, so `lang=en` is confirmed harmless
  rather than confirmed to have selected English. Which `lang` §4.14's earlier
  run passed, if any, is still not on record.

  `am` and `ru` have never been sent. Nor is a wrong spelling known to be
  harmless: what that page does with a `lang` it does not recognise has never
  been observed, and the two entries above about `type` say why a REST finding
  about unknown fields does not carry across to a query string read by an
  ASP.NET page. Settling the rest costs two page loads.

- **The five BackURL parameter spellings are matched exactly and
  case-sensitively, so an upstream rename breaks the merchant's callback
  endpoint rather than being absorbed.** `VposCallback` pins `orderID`,
  `paymentID`, `opaque`, `resposneCode` and `description` as literal keys;
  `paymentId`, `PaymentID`, `responseCode` and every other case or spelling
  variant are not accepted. A missing `paymentID` or `orderID` throws
  `ValidationException` at construction, and a renamed diagnostic reads `null`.

  That is the intended trade — the alternative is a callback handler that keeps
  returning success while quietly seeing no identifiers at all — but it is a
  real exposure and worth naming rather than discovering. If the gateway
  changes the spelling or the case of `paymentID` or `orderID`, every callback
  throws, and it throws at the moment a customer is returning from a payment
  they have already made. If it renames one of the other three, that value
  silently reads `null` instead.

  Note that `resposneCode` is the gateway's own typo, hex-confirmed on the wire
  (§4.8), and it is therefore the wire format. "Correcting" it in a future
  refactor would not be a cosmetic change but a silent break, since the value
  would read `null` while the code looked right.

  No probe has ever observed the gateway sending any of these keys under a
  different spelling, so nothing suggests a rename is imminent — this entry
  records what happens when one arrives, not a prediction that it will.

  P2 (§4.14) is the first **successful** callback ever captured, and it
  corroborates this entry rather than closing it: all five keys arrived under the
  pinned spellings. Two details from that capture matter to anyone reading the
  values. `paymentID` arrived in **lowercase**, where `InitPayment` had returned
  the same identifier uppercase (§4.12); and `description` arrived as
  `Operation Approved ` — **with a trailing space** — which this package hands
  back verbatim rather than trimming. Log those values as diagnostics; do not
  compare against them.

  L2 (§4.24) is the second, and it corroborates the entry harder without closing
  it either. All five keys arrived under the pinned spellings again, on a
  different payment and a different order, and `description` came back
  byte-identical to P2's, trailing space included. What makes L2 the better
  evidence *for this entry specifically* is not the second sighting but where it
  was read: P2 was a capture read by hand, whereas L2's query string was handed
  to `VposCallback::fromQuery()`, so the exposure this entry exists to track —
  the pinned literal-key matching in this package — is the thing that met real
  gateway output and accepted it. A hand-read capture cannot establish that; it
  establishes only what the gateway sent.

  Two observations is still two, and neither is a guarantee about the third. The
  entry stays open: a rename would still throw on `paymentID` or `orderID` and
  still read `null` on the other three, and nothing here makes that less true.

- **SOAP reporting is deferred from v1.0, and what is known about that
  deferral divides sharply into what can be checked and what cannot.**

  Begin with what is neither, because it is the thing most easily misread:
  *deferred does not mean unreachable*. §4.11 records a **working** SOAP
  configuration, and it works because a probe made it work — the envelope, the
  `SOAPAction` header, the SOAP 1.1 binding and the literal response namespace
  were each established by observation against `testpayments.ameriabank.am`.

  State that reach exactly, because it is narrower than "the SOAP surface
  works". The corrected envelope was demonstrated on **`GetTransactionList`
  only**: two probe cases returned HTTP 200 with bodies opening
  `<s:Envelope …><s:Body><GetTransactionListResponse …>`.
  `GetProblemTransactions` has been invoked exactly twice in the life of this
  repository, both under the PDF's wrong envelope, both HTTP 400 with an empty
  body, and never under the corrected one. The other appearances of that
  operation name in the probe record are WSDL document fetches; the operation
  appears there because it is in the service contract, and fetching a contract
  is not invoking what it declares. That the corrected envelope would carry
  `GetProblemTransactions` too is therefore an **inference from a shared
  service contract**, not an observation. Nothing about this deferral is a
  transport problem.

  **Checkable, by anyone with a clone, against the API surface manifest:** no
  REST endpoint returns a full transaction list over a date range.
  `GetTransactionList` is absent from the manifest entirely — not among its
  twelve endpoints and not among its twenty-eight models; a case-insensitive
  search for the string returns zero hits. The only manifest operation taking a
  date range at all is `GetPendingTransactions`, whose request declares
  `StartDate` and `EndDate` and whose response rows carry `OrderId`,
  `ClientName`, `CardNumber`, `Amount`, `PaymentDate` and `ErrorMessage` — a
  per-row error string is the shape of a problem list, not of a complete one.

  **Asserted, and corroborated by nothing in this repository:** that a REST
  equivalent was asked of the bank's vPOS support address and that no answer
  has been received. No probe, no manifest entry and no file anywhere in the
  tree records that exchange. It is owner-supplied, and a reader who wants it
  verified has nowhere here to go — unlike the paragraph above it, which they
  can re-run in a second.

  Two limits on the checkable half, so it is not read as more than it is.
  First, the manifest is scraped from the **REST** Help pages and holds no
  record of the SOAP surface at all, so it can establish that no REST full-list
  endpoint exists but cannot establish that `GetPendingTransactions` is
  *equivalent* to the SOAP `GetProblemTransactions`. That equivalence is
  inferred from the response shape — the `ErrorMessage` column — and is not
  read off anything. Second, absence from a REST manifest is exactly what a
  SOAP-only operation looks like; it is not evidence that `GetTransactionList`
  was withdrawn.

  On that footing the choice is between shipping a second transport for a
  single operation and shipping without it, and v1.0 ships without it: a
  merchant who needs `GetTransactionList` calls that endpoint themselves,
  against §4.13's SOAP hosts and §4.11's hardening requirements. That
  `GetPendingTransactions` covers what remains is manifest-declared and not
  observed — this section's own entry records that the endpoint has never been
  called.

Code written against any behaviour in this section carries an
`@todo unverified` marker naming §13. If an entry here is ever settled — by the
bank, or by a probe against a working sandbox — strike the entry and remove the
markers that point at it.
