# Development history

This is the engineering record behind `davit-vardanyan/ameriabank-vpos-php`, an
**unofficial** PHP client for the Ameriabank vPOS 3.1 payment gateway. The
package is not affiliated with, endorsed by, or supported by Ameriabank.

It exists to answer the questions a contributor will otherwise ask by reading
the diff: why `IndeterminateStateException` is a sibling of `TransportException`
rather than a subclass, why `Amount` has no arithmetic, why the callback object
will not tell you whether the payment succeeded, and why a class in
`src/Response/` is called `CardBindingFiled`.

It is published in the repository and deliberately excluded from the Composer
distribution. Nothing here is required to *use* the package; all of it is
required to *change* it safely.

Claims here divide in two, and the difference matters more than it looks. What
this document says about the **package** — a class name, a method signature, a
guard, a count — is checkable against the `src/` and `tests/` trees it ships
beside, and it was written by checking. What it says about the **gateway** is
not: those findings came from sending real requests to the live sandbox, and
that record is not published. Where a statement about the gateway is an
inference rather than an observation, it says so — and with the evidence
unpublished, that distinction is the only thing carrying the weight. See **Where
the evidence lives** at the end.

---

## The gateway, and why the package is shaped around it

Ameriabank's vPOS is an ASP.NET service. Almost every unusual decision in this
package traces to one of the following, and each one was established the same
way: by sending a real request to the live sandbox and reading what came back,
with the request that produced it recorded at the time.

**HTTP status carries no business meaning.** An authentication failure comes
back as HTTP 200 with a `ResponseCode` of 20. A transport that branched on
status code would report success. `HttpTransport` therefore reads
`getStatusCode()` exactly once, at one line, and that value reaches only a log
record, the returned status/body pair, and a diagnostic argument — never a
branch. `tests/Http/NoStatusCodeDecisionInSourceTest.php` enforces that
structurally, over the token stream, because a second unused read of the status
code turned out to be invisible to every behavioural test *and* to mutation
testing.

**HTTP 500 is a semantic response, not a fault.** The body is
`{"Message":"An error has occurred."}` — ASP.NET's unhandled-exception page —
and it has been observed for well-formed requests. So a 5xx is never retried
(it is indistinguishable from a client input error) and never mapped to a
transport exception (the exchange completed). It raises
`GatewayFaultException`.

**`ResponseCode` changes type by endpoint.** `InitPayment` answers `int 1` for
success; every other endpoint answers `string "00"`. Failure code 20 appears as
`int 20` from one and `string "20"` from the others — and those two forms did
not mean the same thing on the wire. That is why `ResponseCode` is a value
object over `int|string` and not a backed enum.

**Field names carry the bank's own typos, and they are the wire format.**
`CardBindingFileds`, `IsAvtive`, lowercase `rrn`, `PaymentId` in exactly one
model where every other model says `PaymentID`, `OrderId` in exactly one where
every other says `OrderID`. The BackURL callback sends `resposneCode`. These are
reproduced verbatim; "correcting" one would be a silent break, not a cosmetic
change.

**The BackURL callback is unsigned.** The observed parameters are `orderID`,
`resposneCode`, `paymentID`, `opaque` and `description`. There is no HMAC, no
signature, no shared secret. Anyone who knows the callback URL can type
`resposneCode=00` into it. The public API is therefore designed so that
server-side verification is the only ergonomic path to an outcome.

**`InitPayment` is idempotent but not immutable.** It is keyed on
`(ClientID, OrderID)`, and a repeat call returns the same `PaymentID` — but the
later call's parameters overwrite the earlier ones. So it may be retried only
with a byte-identical body, which is a structural requirement on the transport
rather than a note in a docblock.

**For most of this package's development, the sandbox had never completed a
payment.** `InitPayment` succeeded, but the payment page redirected straight
back to the BackURL with `resposneCode=0999&description=Internal+server+error`,
reproduced across six request variants and reported to the bank. Almost every
gap this document records was downstream of that one outage. **It is now
closed**: on 2026-08-26 the page rendered, a real card was charged, and the
payment was read back and refunded twice — the run recorded as cases P1 through
P6, narrated at the end of this document under **The payment that completed**.
A populated `OrderStatus`, a `RefundedAmount` and a populated `BankInfo` have
all now been seen. A real **decline** code has not: that payment was approved,
so what the gateway sends when a card is refused is still unobserved, and it is
the one member of that old list still standing. Anything written against a gap
that remains carries the literal marker
`@todo unverified — see CONVENTIONS.md §13` in the source, and there are 36 such
markers — 31 in `src/` across 26 files, and 5 in `tests/` across 3.
`CONVENTIONS.md` sits at the repository root and ships
in the distributed package, so a reader following one of those markers lands on
a file they already have, and §13 there is the normative reference. This
document narrates the same material for a different purpose — how each gap was
found and what was decided in response — so the catalogue is reproduced below,
under **What the sandbox never confirmed**, and the overlap is deliberate rather
than a second source of truth.

---

## The work, in order

### Package skeleton and the gate line

**What shipped.** An installable, fully gated PHP 8.3 library skeleton: the
Composer manifest, five tool configurations, `src/Vpos.php` holding nothing but
`PROTOCOL_VERSION = '3.1'`, the repository meta documents, and two CI workflows.
No API logic at all.

**Decisions that shaped the public API.** The PHP floor is `^8.3`, and no PHP
8.4+ syntax is used anywhere. PHPStan runs at level 10 with no baseline — the
project was greenfield and starting with debt would have made the analyser
advisory. `composer.lock` is not committed, because this is a library. Guzzle is
dev-only and `suggest`-only: the package is PSR-18 abstract and the consumer
supplies the client. `ext-soap` is never required.

`config.platform.php` is pinned to `8.3.2`, not `8.3.0`, and the reason is worth
keeping because it looks like a mistake. `roave/backward-compatibility-check`
pulls in `roave/better-reflection`, which requires `~8.3.2`. Under an `8.3.0`
platform Composer backtracks to an older bc-check that pins
`nikic/php-parser ^4`, while PHPUnit 12.5 needs `^5`. The pin is a resolver hint,
not a compatibility claim; `require.php` stays `^8.3`.

**Findings worth keeping.** Composer resolves a script's binary through `PATH`,
not through the interpreter that launched Composer. Running
`php8.3 composer test` on a machine with 8.5 installed silently runs PHPUnit
under 8.5. Any verification against the floor must prepend the 8.3 bindir:

```
PATH="/opt/homebrew/opt/php@8.3/bin:$PATH" composer test
```

Second, and equally load-bearing: never read a gate's exit code through a
pipeline. `composer stan | head -20; echo $?` reports `head`'s status, which is
always 0. This produced a false pass that invalidated a whole recorded gate run.
Capture the status first, then inspect the output.

### Repository hygiene and the specification of record

**What shipped.** The scraped API surface manifest became committable, the
vendor PDF became explicitly untracked, the Infection constraint moved to
`^0.35`, and `SECURITY.md` got a real disclosure channel.

**Decisions that shaped the public API.** The specification of record for this
package is `api-surface.json`, scraped from the live ASP.NET Help pages, which
are reflected from the bank's own C# models. It is committed, because a claim
about the API must be checkable without asking anyone for a document. The vendor
PDF is *not* committed: it is Ameriabank's document, sent as an email
attachment, and is not ours to redistribute. It is also wrong about endpoint
names, field types, enum values, validation behaviour and the SOAP envelope, and
it is never cited as justification when it conflicts with the manifest.

Everything that serves development rather than consumers is `export-ignore`d, so
the Composer distribution contains only `src/`, `composer.json`, `LICENSE`,
`README.md`, `CHANGELOG.md` and `SECURITY.md`. Verify before any release tag
with `git archive HEAD | tar -t`; anything in that listing a consumer would not
need is a defect.

**Findings worth keeping.** `git check-ignore` consults the index for a tracked
path and reports no match regardless of the ignore rules. A verification written
without `--no-index` therefore passes while the file it claims to check is still
ignored — the check was vacuous and had been reported as green.

Separately: PHPUnit 13 is published, yet `composer outdated` reports the 12.x
constraint as up to date, because the platform pin filters out releases needing
PHP ≥ 8.4.1. That is empirical confirmation the pin does what it claims.

### The exception hierarchy

**What shipped.** `VposExceptionInterface` extending `Throwable`, plus nine
concrete classes under `src/Exception/`. Each extends a native SPL class *and*
implements the marker interface, so `catch (VposExceptionInterface)` catches
everything from this package and nothing else.

```
VposExceptionInterface (extends Throwable)
├── ConfigurationException          (LogicException)
├── ValidationException             (InvalidArgumentException)
├── TransportException              (RuntimeException)
├── IndeterminateStateException     (RuntimeException)
├── ApiException                    (RuntimeException)   ← the one non-final class
│   ├── AuthenticationException
│   ├── DeclinedException
│   └── DuplicateOrderException
├── GatewayFaultException           (RuntimeException)
└── SerializationException          (RuntimeException)
```

**Decisions that shaped the public API.**

*`IndeterminateStateException` is a sibling of `TransportException`, not a
subclass.* Both arise from a transport failure, and the temptation to nest them
is strong. It is refused because a caller who catches `TransportException` and
retries must not be able to swallow the one case where retrying may
double-charge. When a non-idempotent operation — a capture, a refund, a
cancellation, a binding charge — fails in transport, the SDK does not know
whether the gateway processed it. Keeping the two as siblings makes it
impossible to accidentally write a retry loop that eats that case. The exception
instructs the caller to reconcile via `GetPaymentDetails`; it never guesses and
never auto-retries.

*`ApiException` is the only non-final class in `src/`, and its constructor is
final.* The exception hierarchy is genuinely an extension point; nothing else is.
Every leaf is final. `tests/ClassFinalityTest.php` holds that rule package-wide
with exactly one exemption entry.

*Exceptions carry primitives only.* `ApiException::responseCode()` returns
`int|string` raw, because the wire type varies by endpoint. It deliberately does
not depend on the `ResponseCode` value object; a caller wanting richer behaviour
constructs one. Mapping a code to a subclass belongs with `ResponseCode`, not in
the exception classes.

*Exception messages name fields, never values.* The two factories that accept a
`Throwable` interpolate `$previous::class`, not `$previous->getMessage()` — a
PSR-18 client's exception text can embed a response-body excerpt, and that
depends on consumer configuration the SDK cannot control. The detail stays
reachable through `getPrevious()`.

*Every emittable string lives in a named factory inside `src/Exception/`.* There
are 24 public static factories across that directory and zero inline exception
constructions anywhere else in `src/`. That is not a style preference: it is what
makes the redaction guarantee auditable by reading one directory. A test asserts
it mechanically rather than documenting it in prose.

**Findings worth keeping.** Every defect in this work was found with all gates
green, and none was found by reading. A rogue non-final class carrying
`$cardNumber`, `$expDate`, `$rawBody` and `$password` was dropped into
`src/Exception/` and defeated three structural guards simultaneously — because
each of them iterated a hand-written provider of nine class names instead of the
directory. The rule that came out of it, and that the rest of the package
follows: **a guard's subject list is derived from its source of truth at test
time — the filesystem, reflection, or the manifest — never hand-maintained.** A
hand-maintained list silently exempts everything not on it.

### A coverage driver, and what mutation score does not measure

**What shipped.** A pcov build on the local PHP 8.3, and mutation testing wired
into CI on exactly one matrix cell (the one that has a coverage driver).

**Findings worth keeping.** The first measurement is the reason this work
exists: **100% line coverage over a 75% mutation score.** The suite executed
every line in `src/` and could not distinguish 17 of them from their opposites.
Line coverage says a line ran. It does not say a test could tell that line from
its negation.

All 17 were killed by adding assertions rather than by lowering anything. The
most useful of those assertions is not a literal check but an invariant: no
exception in this package uses the SPL integer code channel
(`tests/Exception/ExceptionBehaviourTest.php::testNoExceptionUsesTheSplCodeChannel`),
because a gateway response code is `int 1` from one endpoint and `string "00"`
from the others and therefore cannot live in an int channel. That makes the test
a statement about the design instead of a snapshot of the current constructors.

A practical note for anyone reproducing the toolchain on macOS: a bare
`pecl install pcov` fails, because Homebrew's `php-config --includes` omits the
pcre2 include path while its bundled `php_pcre.h` includes `pcre2.h`. Build with
`CPPFLAGS="-I$(brew --prefix pcre2)/include"`.

### The enums

**What shipped.** Five enums under `src/Enum/`: `PaymentType` (13 cases),
`Currency` (4, backed by ISO 4217 numeric strings), `Language` (3),
`OrderStatus` (7) and `PaymentState` (7), the last two mapping to each other in
both directions.

**Decisions that shaped the public API.**

*`Enum::from()` is never called on wire data.* `PaymentType` has gaps at 8, 9,
10, 15 and 16 which the bank will fill without notice, and `from()` on an
unknown value throws — inside a hydrator, on a response the merchant needs. Only
`tryFrom()` is used, and every DTO carries both the raw wire value and a nullable
enum, where `null` means "a value this SDK does not yet know". A guard asserts
that no static `from()` call appears anywhere in `src/`; it currently reports
zero. The guard bans *every* static `from()`, not only enum calls, which is why
the wire factories are named `fromWire()` and `fromMinorUnits()`.

*`PaymentType` is checked against the manifest at test time*, not transcribed
once. A case removed upstream, a case added, or a value changed each fail a
different assertion.

*Binding operations accept only `5` and `6`.* `PaymentType::isBindingCapable()`
narrows to `MainRest` and `BindingMainRest`, and it is validated client-side
before dispatch — because an out-of-range `PaymentType` on `GetBindings`
produces an unparseable HTTP 500 rather than a structured error.

**Findings worth keeping.** **The AMD exponent was specified as 0 and is 2.**
The project's own spec declared AMD to have zero minor units, on the reasonable
grounds that Armenian prices are quoted in whole drams and luma are obsolete in
circulation. `Currency::exponent()` returns 2 for all four currencies. Shipping
0 would have made sub-dram amounts unrepresentable and misread every stored
amount by a factor of 100 — an entire class of silent corruption, decided by one
`match` arm. The internal representation follows ISO 4217, not local custom. It
costs nothing on the wire: ten drams serialises as `"10"` under either.

Second: vocabulary was load-bearing and had already drifted. "Minor units" meant
an *exponent* in `Currency` and a *count* in `ValidationException` — one phrase
for two quantities that must never be multiplied. Both halves were renamed
together, to `Currency::exponent()` and `$minorUnitCount`. Renaming one side
would have preserved the ambiguity.

Third, on provenance: only `"051"` (AMD) has ever been accepted by the gateway,
and the claim is a sweep rather than a count — every request this project has
ever sent that carried a currency at all carried `"051"`, save one. `"840"` was
sent exactly once and that
request was rejected by the sandbox's blanket 10-AMD amount rule, which says
nothing about currency handling. `"978"` and `"643"` have never been sent at
all. USD, EUR and RUB are PDF-sourced and unverified, and `Currency` says so.
The claim "numeric, not alpha" is an inference from one accepted value, not an
observation of a rejected alternative.

### The `Amount` value object

**What shipped.** `src/Money/Amount.php` — `final readonly`, private
constructor, two named factories (`fromMinorUnits()`, `fromDecimalString()`),
and the accessors `minorUnitCount()`, `currency()`, `toDecimalString()`,
`equals()`, `isGreaterThan()`.

**Decisions that shaped the public API.**

*No float ever touches a monetary value*, and that is enforced by a source guard
rather than by convention. `toDecimalString()` builds its output with integer and
string arithmetic. There is no `ext-bcmath` dependency and none is needed. The
guard is a deliberately blunt textual scan, which is why the prose in
`Amount.php` steps around naming the inexact numeric type and the formatting
helpers — a cleverer guard is a guard that can fail open.

*`Amount` has no arithmetic.* There is no `plus()`, no `minus()`, no `times()`,
no `allocate()`. Only equality and ordering, and ordering across currencies
throws rather than answering. The reason is that nothing in this package needs
to compose two amounts: every amount it sends is one the merchant supplied, and
every amount it receives is one the gateway reported. Adding arithmetic would
mean choosing rounding and cross-currency behaviour that the gateway has never
been observed to confirm, and — worse — it would invite a caller to compute a
running refund total in the SDK. A merchant must reconcile against
`GetPaymentDetails`, not against a local sum. Refusing to supply the operation is
the only way to say so structurally.

That was written while it was still unknown whether `RefundedAmount` accumulated
across partial refunds, and the decision was made on the strength of the
uncertainty. The completed payment (P3, P4.1b, P4.3b) settled it, and the answer
closes the question in the same direction rather than reopening it: the gateway
publishes `ApprovedAmount`, `DepositedAmount` and `RefundedAmount` side by side,
`RefundedAmount` accumulates, `DepositedAmount` decrements to the balance still
refundable, and `Approved = Deposited + Refunded` held on every body in the run.
There is nothing left for a local subtraction to compute that the gateway has not
already answered — so a `minus()` would now be redundant *as well as* a place for
a rounding error to live. The deferral is resolved, not merely still pending.

*The currency travels with the amount*, so an amount in AMD cannot be paired
with a currency field naming USD. Positivity is checked in exactly one place:
`fromDecimalString()` returns *through* `fromMinorUnits()`, so the rule cannot
drift between the two constructors.

*Overflow is detected by comparing digit strings* — length first, then
`strcmp()` — because an unchecked cast saturates silently at `PHP_INT_MAX`
rather than failing. A 20-digit input is rejected, and padding with leading
zeros cannot fake an in-range value.

**Findings worth keeping.** The recurring defect in this work was documentation,
not code. The parser was correct at the first inspection and never changed;
every problem found was a docblock asserting something about PHP's runtime that
nobody had executed, and two attempted corrections introduced *new* false claims
of the same shape. What finally worked was deleting the explanation rather than
restating it: `strcmp` is now justified by what its operands are — ASCII digits,
leading zeros stripped, so lexical order is numeric order — rather than by a
story about what the engine would otherwise do.

The guard's own limits are stated rather than assumed away. It catches the
constructs it names and is explicitly not a proof of absence: arithmetic
division, a bare decimal literal, `1e3` and integer-overflow promotion all evade
it. Widening it means rewriting it over `token_get_all()`, not adding more
strings.

### `ResponseCode`, the exception mapping, and a coverage floor

**What shipped.** `src/Response/ResponseCode.php` — `final readonly`, private
constructor, `fromWire(int|string)`, with `raw()`, `asString()`, `isSuccess()`,
`isAuthenticationFailure()`, `equals()` and `toException()`. Alongside it, a
100% line-coverage floor (`composer coverage:check`) enforced beside the
mutation gate.

**Decisions that shaped the public API.**

*`ResponseCode` is a value object, not a backed enum.* The code table has around
sixty entries in formats as varied as `00`, `0-1`, `0100`, `0151017` and `514`,
the bank adds more without notice, and the type varies by endpoint. An unknown
code must never throw — a merchant receiving a code this SDK has not seen needs
the code, not a crash.

*`isSuccess()` uses `in_array(..., true)`, and the strict flag is load-bearing.*
Without it PHP admits `'01'`, `0` and `'0'` into `[1, '1', '00']` — precisely
the fail-open the method exists to prevent. A test pins it. `'00'` entered that
set on the vendor PDF's authority alone and carried an unverified marker for it;
the completed payment retired the marker, since `"00"` came back from
`GetPaymentDetails` and `RefundPayment` six times across P3, P4.1, P4.1b, P4.3,
P4.3b and P6.

*Decline codes are unclassified, deliberately.* `toException()` maps integer
`20` to `AuthenticationException` and everything else to a plain `ApiException`.
String `"20"` is *not* mapped, because the two forms were observed meaning
different things: integer `20` came from `InitPayment` with
`"Incorrect Username and Password"` — a credential rejection — while string
`"20"` came from the binding endpoints with
`"Client payment type BindingMainRest is not available"` — an entitlement
refusal. The asymmetry decides every unobserved case: **adding a classification
later is not a breaking change**, because everything built here extends
`ApiException` and a caller catching `ApiException` keeps working, while
**removing one is breaking**, because a caller catching a subclass silently
stops catching. So only what was observed is classified.

`DeclinedException` and `DuplicateOrderException` consequently ship as catchable
types with no producer anywhere in `src/`. That is not an oversight. No probe has
ever completed a payment, so no real decline code is known; and the duplicate-order
condition has never been reproduced — re-registering an existing `OrderID`
returned success and the same `PaymentID`, corroborating idempotency rather than
any duplicate error. What actually raises `DuplicateOrderException` is unknown,
and inventing a trigger would be worse than leaving the type unused.

*`equals()` compares raw values with `===`, so integer 20 does not equal string
`"20"`.* A value object whose purpose is to preserve what arrived should not
paper over the difference between the two wire types — and 20 is the case that
proves it.

**Findings worth keeping.** The coverage floor exists because mutation score has
a structural blind spot: **Infection generates no mutants for uncovered code, so
an untested new class cannot lower the mutation score at all.** The floor
justified itself twice immediately — once against a deliberately untested method,
and once for real, when a new exception factory landed uncovered and dropped
coverage to 97.41% while the entire test suite stayed green.

Also recorded here: `ResponseCode` is public, `fromWire()` is public, and
`isSuccess()` returns true for `'00'` — which makes
`ResponseCode::fromWire($_GET['resposneCode'])->isSuccess()` exactly the
ergonomic forgery path the callback design exists to eliminate. That constraint
was carried forward as a requirement on the callback work rather than left to be
rediscovered, and it is now enforced: `ResponseCode` is named nowhere in
`src/Callback/` or `src/Vpos.php`, by both a textual and a reflection guard.

### `Credentials` and `Environment`

**What shipped.** `src/Config/Credentials.php` and `src/Config/Environment.php`.
`Credentials` is the only class in this package that holds a secret and carries
the whole weight of the security rules.

**Decisions that shaped the public API.**

*There is no `password()` accessor, and there never will be.* The password
leaves the object only inside `merchantFields()` and `userFields()` — the two
request shapes the API actually uses — and the transport injects them.
`ClientID`, `Username` and `Password` never appear in a request DTO the caller
constructs, and a guard rejects any request whose body would carry one.

*The password is held in a `\SensitiveParameterValue`, not a bare string.*
`var_export()` has no interception hook, and against a bare string property it
printed the password verbatim inside a `__set_state()` call. Wrapped, the export
renders as `\SensitiveParameterValue::__set_state(array())` with nothing inside.

*`__unserialize()` throws rather than restoring.* `__serialize()` redacts, so a
restored object would hold the marker string and then fail authentication *while
looking like wrong credentials* — the worst possible failure, because it points
the merchant at the wrong problem.

*The public surface is frozen by name.* One assertion over the eight public
method names kills every leaking shape at once, because every one of them would
have to add a name.

*`Environment` is required and has no default.* It is the second constructor
parameter of `Vpos`, positional and non-optional. A payment library that
defaults to an environment can be pointed at production by omission. And the SDK
has no basis for choosing: neither production host has ever been reached — every
probe ran against the test hosts, and the production REST and SOAP hosts are
transcribed from the vendor documentation and have never returned a byte. Both
carry unverified markers. That the test pair works is not evidence about the
production pair; they are different hosts, and a wrong one fails at the first
live payment rather than in any test.

**Findings worth keeping.** **`__debugInfo()` is consulted by `print_r()` as
well as by `var_dump()`, contrary to the PHP manual**, established on PHP
8.3.28 with a control matrix: an otherwise identical object without the hook
printed the password in full, and adding the hook replaced it with the marker.
The manual documents the hook for `var_dump()` alone. The docblock in
`Credentials.php` is more accurate than the manual on this point, and a test
pins it so the claim cannot rot.

Two ways a security test can be vacuous, both found by mutation rather than by
reading:

- **The stack-trace test passed by default.** `zend.exception_ignore_args` is
  `On` in `php.ini-production`, and with it on *no* argument renders in a trace
  at all — so "the password is absent from `getTraceAsString()`" would have
  passed with `#[\SensitiveParameter]` deleted. The test now forces the INI to
  `0`, restores it in a `finally`, and additionally asserts that the *non-secret*
  username argument **is** rendered, which makes the absence evidence rather
  than an artefact of the runner.
- **PHP truncates trace string arguments at 15 characters.** Had the canary
  reused the 24-character password constant it would have rendered truncated and
  the assertion would have passed for the wrong reason. The canary is 14
  characters on purpose.

And a third, invisible from outside the class: "guards run before any
assignment" cannot be observed behaviourally, because moving a guard after
assignment still throws the same class with the same message and leaves no
object to inspect. It is caught only by constructing without the constructor and
asking reflection whether the property was initialised. That is why the
constructor properties in `Credentials` are **not** promoted — promotion assigns
on function entry, which would make the assertion unfalsifiable.

### Request and response DTOs, and the hydrator

**What shipped.** Eleven request DTOs, thirteen response DTOs (one per operation
plus the nested `CardBindingFiled` and `BankInfo`), and `ResponseHydrator` —
`final`, `@internal`, hand-written.

**Decisions that shaped the public API.**

*Hydration is hand-written and `symfony/serializer` is absent.* This is a direct
consequence of the wire format. `CardBindingFileds` holds `CardBindingFiled`
objects whose active flag is `IsAvtive`; `PaymentDetailsResponse` carries
lowercase `rrn`; `GetPaymentIdResponse` says `PaymentId` where every other model
says `PaymentID`; `GetPendingTransactionsResponse` says `OrderId` where every
other says `OrderID`. A convention-based mapper has exactly two options here:
force the bank's typos into the PHP API, or silently drop the fields it cannot
match. PHP property names are idiomatic (`isActive`, `cardBindings`), and the
mapping between them and the wire is explicit, exact, and stated once per field
in the hydrator.

*The manifest is an enforced contract, not a consulted document.*
`tests/Support/ManifestConformanceTest.php` reads `api-surface.json` at test
time and checks three axes: a field renamed upstream, a field the SDK forgot to
map, and a DTO constructor that drifted from the hydrator's named arguments each
fail a different assertion.

*`Currency` is derived from the `Amount`, never accepted as a parameter.* Two
independent sources for one currency is a money bug with no symptom: an `Amount`
in AMD beside a `Currency` field naming USD would serialise happily. Deriving it
makes the disagreement unrepresentable, and it keeps the request body a pure
function of the object — which is what the byte-identical-retry rule requires.

*Every monetary field carries a `?string` raw companion beside its `?Amount`.*
This is not defensive programming. When it was written, every
`GetPaymentDetails` body on record had returned `"Currency":""` beside four
decimal fields, so a monetary value that could not be turned into an `Amount`
was **observed**, not hypothetical, and the typed `depositedAmount` had never
once been populated in practice. The completed payment changed the first half of
that: P3 and its successors return `"Currency":"051"`, and the typed `amount`,
`approvedAmount` and `depositedAmount` are populated on those bodies. It did not
change the conclusion. A populated `Currency` is necessary for a typed `Amount`
and not sufficient — the constructor refuses a zero, so `refundedAmount` is
`null` on P3 while `refundedAmountRaw` reads `"0.0"` — and the earlier blank
bodies were real responses that a merchant's code has to survive. The raw
companion is the field that can always be read, which is exactly why it exists
beside every typed one.

*No constructor defaults on any response DTO.* A field the hydrator forgets to
pass becomes an `ArgumentCountError`, not a silent null. The cost is that tests
must name all thirty-odd arguments for the two large models; that verbosity is
the guard.

*`GetPendingTransactions` has no `ResponseCode` envelope.* Its response is a
top-level JSON array of rows, so there is nowhere to put a response code even in
principle. The transport special-cases nothing for it: a `ResponseCode` is
branched on, a `Message` without one is a fault, and a body with neither is
returned as decoded.

*`ActivateBinding` and `DeactivateBinding` are non-idempotent* even though the
project's own retry table omits them. Silence is not permission; both change
state. Six of the eleven requests report `isIdempotent(): false`.

**Findings worth keeping.**

**The manifest declares no requiredness at all.** The "Additional information"
column is the literal string `None.` for every field of every model — a
distinct-value set of cardinality one. That is ASP.NET Help emitting silence for
a model with no `[Required]` annotation, not a declaration that everything is
optional. Requiredness was decided conservatively, per field, with the reason
recorded: required when the operation is unaddressable or meaningless without
it.

**A dead branch that was invisible to line coverage, to the static analyser, and
to mutation testing.** `ResponseHydrator` then had a single method,
`readAmount()`, that both validated a wire key and built an `Amount` from it —
the method no longer exists, and the paragraph below is what replaced it. Its
final `else` was unreachable from the public surface: all eight call sites were
preceded, in the
same constructor call on the same wire key, by a reader accepting the identical
scalar set, and PHP evaluates arguments left to right. Both rejections carried
byte-identical wording, so no message assertion could tell them apart. The 100%
line-coverage figure for that one line was being produced by a reflective test —
the clover `count` was 1 against 12–59 for its neighbours, and that asymmetry
turned out to be the cheapest available detector.

The static analyser was *correct* to stay silent: the value came from
`$data[$key] ?? null` over `array<array-key, mixed>`, so the branch was
genuinely reachable in the type system. The deadness was a whole-program
property no analyser at any level can see. Mutation testing was equally silent —
measured, not assumed: a throw-shaped dead arm yields full mutation coverage and
says nothing at all. Only an *assignment*-shaped dead arm surfaces, and only
indirectly, by making two negated conditions behaviourally identical, which
drops the score to about 99%.

The fix removes the class of defect rather than the lines, by splitting
`readAmount()` into the three methods that stand in `src/Support/ResponseHydrator.php`
today. `readDecimalScalar()` reads the wire key **once** into a narrowed scalar,
and that one validated value is handed to two independent derivations:
`renderDecimal()`, which produces the raw `?string` companion, and
`buildAmount()`, which produces the `?Amount`. With the parameter narrowed,
`buildAmount()`'s arms are exhaustive and there is no `else` left to be dead.

**And 99% would not have failed the build**, because the mutation floors were
set at 90 while the tree held 100. A floor at 90 over a tree at 100 is not
headroom; it is ten points of silent regression budget, and an assignment-shaped
dead arm sits inside it. Both floors were raised to 100, and the raised floor
was proven to bite by injecting exactly that defect and watching the gate exit 1.
If a genuinely equivalent mutant ever forces the score down, the answer is an
ignore annotation with a stated justification — never a lower floor.

### The HTTP transport

**What shipped.** `src/Contracts/RequestInterface.php` (`operation()`,
`isIdempotent()`, `requiresClientId()`, `toArray()`), implemented by all eleven
request DTOs; `src/Http/HttpTransport.php`; `Redactor` and `FailureRedactor`;
three redacted PSR-18 stand-in exceptions; `GatewayFaultException`; and
`src/Support/ExceptionState.php`. Everything under `Http\` and `Support\` is
`@internal`.

**Decisions that shaped the public API.**

*`GatewayFaultException` is a sibling of `ApiException`, not a subclass.* An
`ApiException` means the gateway gave a business answer and the answer was no. A
fault means it gave no answer at all: the ASP.NET envelope
`{"Message":"An error has occurred."}` carries no `ResponseCode`.
`ApiException`'s constructor is final and *requires* a response code, so a
subclass could exist only by synthesising a code the gateway never sent — and
`responseCode()` would then publish a fabricated value that no caller could
distinguish from a real one. Keeping them siblings makes that fabrication
impossible to introduce by accident. It is not a `TransportException` either:
the exchange completed, and a fault is a deliberate refusal that must never be
retried.

*The transport throws on evidence of failure, never on the absence of evidence
of success.* One endpoint's response has no `ResponseCode` anywhere in it, and a
transport that demanded one would make that endpoint unreachable. Shape
validation belongs to the hydrator.

*Retry is a conjunction, and neither half is caller-configurable.* A request is
retried only if its own `isIdempotent()` says so **and** its operation is absent
from a `NEVER_RETRY` list naming `ConfirmPayment`, `RefundPayment`,
`CancelPayment` and `MakeBindingPayment`. The list is redundant with the DTOs by
design — it models the threat of a third-party `RequestInterface` implementation
that lies about being repeatable. There is no option, argument or setter that
overrides either half. On a transport failure for a non-idempotent operation,
exactly one request is sent and `IndeterminateStateException` is thrown.

*Encode-once is structural, not a promise.* `dispatch()` takes a
`string $encodedBody` and never receives the request object or the field array,
so nothing inside the retry loop *can* re-encode. Re-encoding would require a
signature change — a visible edit, not a slip. This matters because `InitPayment`
overwrites its own parameters on a repeat call, so a rebuilt body on attempt two
silently mutates the registered order.

*Redaction matches against the runtime key, not a baked name list.* The manifest
is export-ignored and therefore absent from an installed package, so it cannot be
read at runtime; matching rules against the key that actually arrived is strictly
stronger than a fixed list. A separate test pins the **clear** list exhaustively
against the manifest, so any new upstream field fails the build until a human
classifies it. Worth knowing: `Cvv2`, `Cvc`, `SecurityCode` and `Token` match no
redaction stem — confirmed by execution — which is exactly why the clear list is
the real upstream guarantee rather than the rules.

*Headers are dropped from the redacted stand-in, not redacted.* Redaction keys on
the key, and header names are a namespace the key set was never derived against:
`X-Api-Password` would match the password stem, but `Authorization`,
`Proxy-Authorization` and `Cookie` match nothing and would sail through as
values. Dropping loses nothing the URI and two package constants do not already
carry.

*Three stand-in classes, not one.* A single class implementing all three PSR-18
interfaces would answer `true` to both the network and request interfaces — and
that distinction is the entire mapping. A caller reading `getPrevious()` would be
told the opposite of what the transport decided.

**Findings worth keeping.**

**The leak was not where it was diagnosed to be.** The first diagnosis had
`print_r()` reaching the password by walking the previous exception's request
body. Measurement said otherwise: Nyholm, Guzzle and Diactoros all back a body
with a resource, which printers render as `Resource id #770` and stop at. The
actual leak was a retained stack-frame argument, because
`zend.exception_ignore_args` is Off in `php.ini-development`. The finding's
*conclusion* held — the credential was reachable — but closing it as written
would have left the leak open. Closed with `#[\SensitiveParameter]` at four
sites, three of which carry card data rather than credentials.

**That security fix had a blast radius nobody predicted.**
`#[\SensitiveParameter]` made *every* exception the transport throws
unserialisable, because the engine refuses to serialize a
`SensitiveParameterValue` held in a trace — including a routine declined
payment. A merchant queueing a failed payment job would have hit a fatal error
in the worker, naming a PHP internal class and nothing about payments. Fixed
with `__serialize()`/`__unserialize()` that scrub rather than refuse, on every
exception in the hierarchy; `chainDropped()` on the marker interface reports
whether the previous-exception chain survived the round trip. Unlike
`Credentials`, these do not throw on restore: a restored decline is still a
usable decline, and throwing would reintroduce the fatal the change exists to
remove.

**A second, unused `getStatusCode()` read was invisible to every behavioural
test and to mutation testing.** Only the structural token-stream guard caught
it. That is the argument for structural guards existing at all: some properties
are about the shape of the code, and no amount of behavioural testing observes
them.

One assertion was reported as unable to fail rather than concealed: comparing
two sent request bodies passes even against a re-encoding transport, because all
eleven DTOs are deterministic. It was kept, and a counting test double that
fails if the request object is asked for its body twice was named as the real
guard.

### The clients and the `Vpos` entry point

**What shipped.** Three operation clients and a composition root, making eleven
REST operations reachable:

```php
$vpos->payments()->init($request);              // InitPaymentResponse
$vpos->payments()->details($paymentId);         // PaymentDetailsResponse
$vpos->payments()->confirm($paymentId, $amount);
$vpos->payments()->cancel($paymentId);
$vpos->payments()->refund($paymentId, $amount);
$vpos->payments()->paymentIdForOrder($orderId);

$vpos->bindings()->pay($request);
$vpos->bindings()->all(PaymentType::BindingMainRest);
$vpos->bindings()->activate($cardHolderId, $type);
$vpos->bindings()->deactivate($cardHolderId, $type);

$vpos->reports()->pending($from, $to);          // list of DTOs

$vpos->paymentPageUrl($paymentId, Language::Armenian);   // no API call
```

**Decisions that shaped the public API.**

*Which operations take a DTO and which take primitives was derived, not chosen.*
Business-field counts came from the manifest with the three credential fields
excluded: `InitPayment` (9 fields) and `MakeBindingPayment` (8) take request
objects; the other nine take primitives.

*Every method returns a hydrated response DTO, never an array.*
`reports()->pending()` returns a list of them, because that operation alone has
no response-code envelope to wrap.

*The binding listing is `all()`, never `list()`.*

*No validation is duplicated in a client.* The binding DTOs already enforce the
5/6 rule and the `PaymentID`-addressed DTOs already reject blanks; clients
construct the DTO and let it throw. Likewise `Vpos` re-validates nothing the
transport validates — a duplicate bound would be a second copy that could drift.

*`Vpos` stores `Environment`, not `HttpTransport`.* Storing the transport would
have required a public accessor, leaking an `@internal` type onto the public
surface. The transport appears in `Vpos.php` only as an import, as prose, and as
a constructor local. `Vpos` is `final readonly`, which also forbids dynamic
properties, so "no mutable state" is enforced by the language rather than by
modifiers a future edit could drop one at a time.

*`paymentPageUrl()` lives on the entry point*, because it performs no API call —
it is a pure function of the environment, the `PaymentID` and two display
choices. A blank `PaymentID` throws rather than producing a broken page, which
is not hypothetical: a failed `InitPayment` answers with `"PaymentID": ""` —
empty string, never null.

*The payment page's `type` parameter is optional, last, and kept out of every
runnable example.* It is the weakest-sourced claim in this package: its
provenance is a third-party package's documentation, it appears in neither the
vendor PDF nor the manifest, and no probe has ever sent it. When this was
written, none could: the sandbox payment page never rendered. That reason is now
gone — the page renders — so the claim is untested rather than untestable, and
one payment opened twice would settle it. Threading it through a copyable
example would read as an endorsement at the exact moment a merchant copies code.

**Findings worth keeping.**

**A guard that greps a docblock for `@internal` is defeated by a docblock that
narrates the phrase.** Every constructor taking an internal type opens its
docblock with prose containing "`@internal`", so deleting the real tag left a
`str_contains()` guard green. Replaced with a line-anchored match. This is
visible only by running the mutation; reading the test would never reveal it. The
general rule the project now follows: **a guard is not accepted until its
mutation has been executed and the guard has been seen to fail.** "The assertion
reads correctly" is not a substitute for having watched it go red.

**Replacing a hand-maintained list with a derived one was proven, not
argued.** Having shown that a guard deriving the internal-type list from the two
`@internal` directories catches a leak, the identical mutation was re-run with
the old hand-written literal restored — and passed, exit 0. That converts "this
is better" from an opinion into an observation.

**Documentation can re-open a hole the design closed.** Callback guidance that
told the merchant to check the returned order id and the amount — and never the
payment's *status* — is satisfied by a forged callback carrying a **real
`paymentID` for the merchant's own registered-but-unpaid order**. The transport
decides success from the response code *of the query*, so a successful query
*about* a declined or never-attempted payment returns perfectly normally. The
safety net had the hole in it.

**And the fix's own mandatory check was half-broken.** One of the three
replacement checks read `depositedAmount`, which — on every response shape
observed *at that time*, in August 2026 before the payment page began to render
— was `null`, because `GetPaymentDetails` returned `"Currency":""` and the typed
`Amount` cannot be built without a currency. A merchant would have found a
mandatory check permanently red and deleted it, unwinding a third of the fix.
Corrected to the raw companion field, with an instruction to fail closed when it
is absent.

That defect was real when it was found, and the correction was right for a
second reason that only emerged later. The completed payment returns
`"Currency":"051"`, so the typed `depositedAmount` *is* populated now and the
original check would no longer be permanently red — but it would be **wrong**
instead, because `DepositedAmount` turned out to be the remaining refundable
balance rather than the captured total, and it decrements as refunds run. The
guidance in `README.md` was moved again, to `approvedAmountRaw`, which is the
one amount field that does not move. A check that reads correctly and answers
falsely is worse than one that is visibly broken.

### `VposCallback`, and forced verification

**What shipped.** `src/Callback/VposCallback.php`, constructed only through
`fromQuery(array)` or `fromServerRequest(ServerRequestInterface)`, exposing
exactly `paymentId()`, `orderId()`, `opaque()` and `untrustedDiagnostics()`; and
`Vpos::verify(VposCallback): PaymentDetailsResponse`.

```php
$callback = VposCallback::fromQuery($_GET);   // unverified marker type
$details  = $vpos->verify($callback);         // server-side round trip
```

**Decisions that shaped the public API.**

*There is no success accessor anywhere, and there is no route to one except
`verify()`.* The callback is unsigned, so anything derived from `resposneCode`
without a server-side round trip is an attacker-controlled boolean.
`resposneCode` and `description` *are* retained — throwing away the gateway's own
diagnostic text would make failures harder to support — but only through
`untrustedDiagnostics()`, whose name is the warning, and which a reader has to
type in full before they can misuse it. `ResponseCode` is named nowhere in
`src/Callback/` or `src/Vpos.php`, enforced both textually and by reflection over
parameter *types*, since a method taking a `ResponseCode` built from caller input
passes any name-based check while being precisely the forbidden thing.

*`verify()` round-trips `GetPaymentDetails` for the callback's `PaymentID` and
cross-checks the order in three branches.* A `null` `OrderID` **skips** the
comparison, because a check cannot be made against an absent value. A present
but blank `OrderID` — empty or whitespace-only — **refuses**, under its own named
condition, because the gateway named no order to check against and reporting a
mismatch would be a false diagnosis. Anything else is compared **byte for byte**,
so `'1001'` and `'01001'` remain different orders; the blankness test is the only
place `verify()` trims anything.

That refusal was written as a live trade-off, and the argument for it ran on an
absence: nothing established that `GetPaymentDetails` ever populated `OrderID`,
no probe had completed a payment, and a present-but-empty field was not
hypothetical at this endpoint, since `"Currency":""` came back from the same
responses. The fear was that a completed payment would also return a blank
`OrderID`, in which case `verify()` would refuse every callback — an outage
rather than a silent weakening, which was the deliberate choice of failing loud
over failing quiet, but an outage all the same.

The completed payment answers it, in the design's favour. P3 returns
`"OrderID":"4565037"` beside `ResponseCode` `"00"`, and the callback that
preceded it (P2) carried the same `orderID`, so the comparison branch was
reached and passed. The populated shape — the one that had never been seen —
is what a paid order produces, on that payment and on the second one that
followed it (L3, L4.1b, L4.3b). The refusal branch
still has never been reached by any observed response, and now for a better
reason: no body has arrived blank *and* success-coded. The `null` path is
unchanged and a merchant should still know what it means — no order-identity
protection at all, so compare `$details->orderId` against your own record
yourself if you hold one.

*Both new rejection conditions take zero parameters and embed no value.* Every
other factory in `src/Exception/` that embeds a value embeds a locally computed
one. The callback's `orderID` is attacker-controlled and an exception message
reaches logs, so embedding it would permit log forgery.

*The five wire spellings are matched exactly and case-sensitively.* `orderID`,
`paymentID`, `opaque`, `resposneCode`, `description` — `paymentId`, `PaymentID`
and `responseCode` are not accepted. A missing or blank `paymentID` or `orderID`
throws at construction; a renamed diagnostic reads `null`. This is a real
exposure and worth naming rather than discovering: if the gateway ever renames or
re-cases one of the two identifiers, every callback throws, and it throws at the
moment a customer is returning from a payment they have already made. The
alternative — a handler that keeps returning success while quietly seeing no
identifiers — is worse.

*Non-string query values are refused as malformed, never coerced.*
`&description[]=x` throws in both the required and the optional reader, because
`(string) ['a']` yields the literal `"Array"` with a warning. This is stricter
than the response hydrator, deliberately: a query string has no declared types to
drift between, so there is nothing to accommodate.

*Identifiers are stored verbatim and never trimmed*, even though blankness is
checked on `trim()`. A silently repaired identifier that then fails at the
gateway is harder to diagnose than the one actually sent. Likewise an
empty-string optional parameter is retained as `''` rather than normalised to
`null`: `description=` and an omitted `description` are different events.

**Findings worth keeping.**

**A blocklist ships everything it did not predict, and this was demonstrated
rather than argued.** A method

```php
public function isSuccessful(VposCallback $callback): bool
{
    return $callback->untrustedDiagnostics()['resposneCode'] === '00';
}
```

was added to a copy of this repository together with the one test a contributor
would naturally write beside it, and **the entire gate line went green** —
tests, 100% line coverage, mutation score 100, static analysis, style, all of it.
The assertion count even *rose*. It takes no forbidden parameter name, it names
no forbidden type, and its own name was simply absent from the outcome-name list,
which compares by exact key. No near-miss is involved and none is needed: an
exact lookup admits every name not on the list, `paymentWasMade()` exactly as
much as this one. A merchant calling it on a forged query string gets `true` with
no round trip — free goods, past every gate. A forbidden `verifyFromQuery(array)`
overload was proven green the same way.

The answer was to pin the entry point's public surface **by equality**, so every
new public method on `Vpos` fails the build until a human classifies it. That
closes both holes with one assertion, and closes the ones nobody has thought of
yet. The blocklist stays, because it *explains* the refusal by naming the
forbidden channel; the allowlist is the only half that can *bound* the surface.

**`resposneCode` is real.** It is the gateway's own misspelling, captured from a
live BackURL redirect and confirmed in hex —
`726573706f736e65436f6465` — so it cannot be a transcription error anywhere in
the chain. It is therefore the wire format. Renaming it in a future refactor
would not be a cosmetic change but a silent break: the value would read `null`
while the code looked right.

**Six of twelve `src/`-walking guards read only one directory level.** A class
nested inside `src/Request/` or `src/Exception/` was invisible to them, and a
non-final probe class demonstrating this passed the entire suite under the old
walkers. All twelve now recurse. The count itself is the lesson: the number was
carried in prose as "seven", and it was wrong in both directions — deriving it
from the filesystem is what produced twelve.

**A guard can be green while performing zero assertions.** One returned early
when a class had no docblock; PHPUnit reported it *risky*, not failing, and it
was found only by feeding it a nested fixture.

### The finality guard, and closing the open questions

**What shipped.** `tests/ClassFinalityTest.php`, holding the final-by-default
rule package-wide: it derives its subject list from a recursive walk of `src/`
(58 files across 12 directories) and reads declarations from a `token_get_all()`
token stream. It carries exactly one exemption, `ApiException`, with its reason
inline. The remaining open scope questions were settled and written where they
belong, and the README gained a "Not implemented" section.

**Decisions that shaped the public API.**

*Detection is by token stream, not by regex.* Forty-nine adversarial constructs
were executed against the real detection methods — `new class {}`, `Foo::class`,
`#[Attr(Foo::class)] final class`, heredoc and nowdoc, inline HTML,
`enum`/`class` used as method names, `final` in a constant modifier, `Final` as a
namespace segment, and both orders of `final readonly` — with zero false
negatives and zero false positives. A pattern match loses to several of those.

*The exemption assertion is derived, not a literal.* The obvious form,
`assertSame(['…'], self::CONST)`, is rejected by the analyser as an
already-narrowed tautology — which is exactly the decorative shape the
falsifiability rule exists to catch. It instead asserts a count and an equality
against the *scan result*, so it fires both on a second non-final class and on a
stale exemption entry.

*Anonymous classes are a documented blind spot* rather than a silent skip. They
have no name, so nothing can extend them and finality is vacuous for them.

**Findings worth keeping.** The gap was real rather than theoretical: a non-final
class in a nested directory passed the whole pre-existing suite, exit 0. The
control run — the same fixture with the new test removed — is what turned "this
guard is an improvement" into an observation. One mutation in the same batch
discriminated nothing, going red against both the old and the new mechanism, and
it was recorded as a negative result rather than presented as evidence.

The most durable finding is the asymmetry that closed the project: **the guard
was mechanically sound and the prose written to justify it carried five false or
overstated factual claims, two of them in the changelog, which ships.** That is
the shape of nearly every real defect in this repository's history — the code
executes, the sentence about the code does not. It is why this document was
written against the tree rather than against the record.

---

## The standing conventions

Four rules run through everything above, and a contributor who breaks one will
usually break the package quietly rather than loudly.

1. **Derive a guard's subject list from its source of truth at test time** — the
   filesystem, reflection, or the manifest — never from a hand-maintained
   literal. A hand-maintained list silently exempts everything not on it. Where
   the source of truth genuinely cannot name a subject (the raw-response
   *channel* that no manifest of *fields* can describe), the residual entries
   are held in a named constant, each carrying a one-line reason.
2. **Do not accept a guard until you have watched it fail.** Mutate the exact
   condition it claims to catch, run the suite, see it red, then restore from a
   checksummed copy and see it green again. Restore by copy, never with
   `git checkout` — that cost a file once, when a mutation harness reverted an
   uncommitted deliverable to `HEAD`.
3. **Never lower a quality floor.** Line coverage is 100% and the mutation
   floors are 100. A floor below what the tree holds is a silent regression
   budget. If an equivalent mutant ever forces the score down, answer with a
   justified ignore, never a lower threshold. And remember what each gate cannot
   see: mutation score is blind to wholly untested code, so `coverage:check` is
   the gate that catches a new class with no tests.
4. **State what was observed, separately from what was inferred.** A value
   appearing in a probe *request* proves nothing; check the response. Code
   written against unverified behaviour carries the marker
   `@todo unverified — see CONVENTIONS.md §13`, whose catalogue is **What the
   sandbox never confirmed** below.

---

## What is not implemented, and why

**v1.0 ships eleven REST operations**: `InitPayment`, `GetPaymentDetails`,
`ConfirmPayment`, `CancelPayment`, `RefundPayment`, `GetPaymentId`,
`MakeBindingPayment`, `GetBindings`, `ActivateBinding`, `DeactivateBinding`,
`GetPendingTransactions`. Read that as what the package *exposes*, never as what
the gateway has been *observed to do* — several of the eleven have never
succeeded against the sandbox. Bindings and `ConfirmPayment` are not permitted on
the sandbox client, and `GetPendingTransactions` has never been called at all, so
its success shape is the manifest's declaration alone and its failure shape is
entirely unobserved.

**`GetTransactionList` and `GetProblemTransactions` are not implemented.** Both
are SOAP-only. This is a scope decision, not a technical obstacle: a working SOAP
configuration is on record, established by observation against the test host —
the envelope, the `SOAPAction` header that routes the call, the SOAP 1.1 binding,
and the fact that the response namespace has no URI scheme so XPath must match it
literally. What is *not* on record is any successful invocation of
`GetProblemTransactions` under that corrected envelope; that it would work too is
an inference from a shared service contract.

The REST half of that reasoning a reader can check in a second; the SOAP half
they cannot, because it rests on the unpublished sandbox record. The checkable
half: `GetTransactionList` is absent from `api-surface.json` entirely — not
among its endpoints and not among its models, zero hits case-insensitively. The only
manifest operation taking a date range is `GetPendingTransactions`, whose rows
carry a per-row `ErrorMessage` — the shape of a problem list rather than a
complete one, which is where the claimed equivalence to `GetProblemTransactions`
comes from. That equivalence is inferred from a response shape and is not read
off anything, and absence from a REST manifest is exactly what a SOAP-only
operation looks like; it is not evidence the operation was withdrawn.

On that footing the choice is between shipping a second transport for a single
operation and shipping without it, and v1.0 ships without it. A merchant who
needs `GetTransactionList` calls the SOAP service directly. If you implement it
here later: build the envelope by hand over the same PSR-18 transport so it
shares the logging, redaction and timeouts, do not require `ext-soap`, omit the
vendor PDF's `<Action s:mustUnderstand="1">` header element (it causes HTTP 400 —
the `SOAPAction` HTTP header is what routes the call), use SOAP 1.1 because 1.2
returns 415, and parse with `DOMDocument` plus `DOMXPath` under `LIBXML_NONET`
with entity loading disabled. XXE hardening is mandatory, and the SOAP host is
slower than REST, so give it a longer timeout.

**`SSNCheck` is excluded.** It is unrelated to the payment lifecycle, and it
carries PII obligations that nothing else in the package does: `SSN` and
`IdentifierType` are Armenian national identity data, which the security rules
require be handled exactly as credentials are. Shipping it would add that
obligation to a release that otherwise carries none.

**No framework bridge.** The Laravel integration lives in a separate repository,
`davit-vardanyan/ameriabank-vpos-laravel`. This package stays
framework-agnostic, which is also why its code style is not Pint.

---

## What the sandbox never confirmed

Some of this package is built against a specification that the sandbox has never
confirmed. Read the previous section as being about *absence* — what is listed
there does not exist, so there is nothing to call — and this one as being about
*doubt*: everything below is implemented and shipped, and it is only the
confirmation that is missing. Each place is marked `@todo unverified` in the
source; there are 34 such markers — 29 in `src/` across 25 files, and 5 in
`tests/` across 3. That total falls as entries here are settled: it was 36
before the second completed payment discharged the two that stood over
`Vpos::verify()`'s own request and over `GetPaymentIdResponse`, neither of which
had ever been exercised against the gateway before that run.
Re-derive it rather than trust it — `grep -ro '@todo unverified' src tests |
wc -l` — because a restated count is exactly the kind of claim this document has
had to correct before.

The distinction between what was **observed** and what was **inferred** is
maintained deliberately throughout. Where something below is an inference,
corroborated by nothing, or asserted without a trail anywhere in this
repository, it says so in those words. Do not read a fluent sentence as an
observed fact.

### The sandbox payment page was broken, and it was the root of most of the rest

This entry is kept because it explains the shape of everything under it, but it
is **closed**. For the whole of this package's development, `InitPayment`
succeeded while opening `Payments/Pay?id=…` redirected immediately to the
BackURL with `resposneCode=0999&description=Internal+server+error`, reproduced
across six request variants — minimal fields, an integer amount, an undocumented
`PaymentServiceType`, a public HTTPS `BackURL` instead of localhost, `Opaque`
omitted, and an explicit `Timeout`. All six registered successfully and all six
redirected. It was reported to the bank.

On 2026-08-26 the page rendered and a payment went through. What that run did
and did not establish is the closing section of this document, **The payment
that completed**.

### What that outage held up, and what the completed payment settled

- Whether `OrderStatus` arrives as `"2"` or as `"payment_deposited"`.
  **Settled, and the question was malformed.** They are two separate fields and
  both are populated: `"OrderStatus":"2"` beside
  `"PaymentState":"payment_deposited"` on a deposited payment, and `"4"` beside
  `"payment_refunded"` on a refunded one. Each hydrates to its own enum, and the
  `ctype_digit` guard in the hydrator handled both without a change. The raw
  value still sits beside every nullable enum, for the reason it always did — a
  member the bank adds without notice.
- Whether `RefundedAmount` accumulates across multiple partial refunds, or
  reports only the most recent one. **Settled: it accumulates** — 0.0, then 4.0,
  then 7.0 across two refunds of a payment of 10.
- The error code for an over-refund. **Settled: `"07"`**, with
  `Refund amount exceeds deposited amount`. The code is overloaded — a cancel
  refused by the payment's state answers `"07"` too, with a different message.
- Whether `BankInfo` is ever populated. **Settled: partially.**
  `BankCountryCode` and `BankCountryName` came back populated; `BankName` is
  still empty on every body ever observed.

One member of the old list is still standing, and it is the one this run could
not reach: **a real decline code.** The payment was approved. What the gateway
sends when a card is refused remains unobserved, which is why nothing here maps
a response code to a meaning.

### Amount precision is unverified for every currency

Two probes sent a fractional amount: 10.55 AMD (case A7.1) and 10.55 USD
(case A7.4). Both came back with `ResponseCode` 560 and `"In test mode amount
must be 10 AMD"` — the sandbox's blanket amount rule, which fires *before* any
precision check. Neither result says anything about whether the gateway accepts
a fractional amount.

`Currency::exponent()` therefore returns 2 for all four currencies because that
is the ISO 4217 exponent — **the standards-correct choice, not an observed
one.**

The second completed payment does not narrow this by one step, and it is worth
saying so at the point a reader will think otherwise. That run sent `"10.00"`,
`"4.00"` and `"3.00"` and the gateway accepted all three, which observes the
**encoding** — a quoted decimal string, which nothing had ever put on this wire
before — and says nothing about **precision**, because every fraction in it was
`.00`. No fractional amount has ever reached the gateway in either direction.

Related, and on the same footing: only `"051"` (AMD) has ever been accepted —
every request this project has ever sent that carried a currency at all carried
that value, save one, and a count is deliberately not given here because a count
reads as the whole record the moment the next run adds to it. `"840"` was sent
exactly once, and that
request was rejected by the amount rule above, which says nothing about currency
handling. `"978"` and `"643"` have never been sent at all. USD, EUR and RUB are
transcribed from the vendor PDF and unverified. And since no probe has ever sent
an alpha code, "the wire wants ISO numeric, not alpha" is an inference from one
accepted value rather than an observation of a rejected alternative.

### Bindings and two-step payments were never permitted

The sandbox client this package was built against does not have them enabled.
`ConfirmPayment`, `MakeBindingPayment`, `GetBindings`, `ActivateBinding` and
`DeactivateBinding` therefore follow the API manifest and no observed success
response. `GetBindings` was called ten times and `ActivateBinding` and
`DeactivateBinding` once each; the answers were refusals, which is how the
5-and-6 payment-type restriction and the plural-endpoint 404s came to be known,
but not one of them is a success shape.

**That entitlement gap turns out to leave one cell of the exception hierarchy
structurally unobservable, not merely untested.** String `"20"` carries at least
two unrelated meanings. From `ActivateBinding`, `DeactivateBinding` and
`GetBindings` it is an **entitlement refusal** — six probe cases, every one of
them carrying `"Client payment type BindingMainRest is not available"`. From
`GetPaymentId` it is a **credential rejection**, carrying `"Incorrect Username
and Password"` (case L6.2). The two are separable only by the operation, which
merely correlates with them across those seven observations, or by the message,
which `CONVENTIONS.md` §4.17 forbids this package to interpret. And the
observation that would decide it cannot be made: because the client has no
binding entitlement, a wrong password has never reached a binding endpoint at
all, so a rule keyed on the operation would rest on a set with one permanently
empty cell and would misclassify that cell the moment it filled. `ResponseCode`
therefore treats **only integer `20`** as an authentication failure — the form
`InitPayment` returns — which means, stated plainly because no caller should have
to discover it from behaviour, that **`AuthenticationException` is currently
unreachable outside `InitPayment`**. Catch `ApiException` and read
`responseCode()` and `responseMessage()` instead; the gateway's own text is
there. Adding the classification later is non-breaking, since
`AuthenticationException` extends `ApiException`; removing a wrong one would not
be. The first request to send when binding permissions arrive is a wrong password
against `GetBindings`.

### The duplicate-order condition has never been reproduced

The vendor PDF attributes response codes `01` and `08204` to a duplicate
`OrderID`. Neither appears in the API manifest, and neither has ever been seen on
the wire. Case A5 re-registered an `OrderID` that case A3 had already
registered, and the gateway answered `ResponseCode` 1, `"OK"`, returning **the
same `PaymentID`** A3 had been issued — corroborating the idempotency described
earlier in this document rather than any duplicate error.

What actually raises `DuplicateOrderException` is therefore unknown. The type
ships as a catchable subclass of `ApiException` with no producer anywhere in
`src/`, which is the honest state of affairs: inventing a trigger would be worse
than leaving it unused.

### Neither production host has ever been reached

Every probe ran against `servicestest.ameriabank.am` and
`testpayments.ameriabank.am`. The production REST base and the production SOAP
host — both returned by `Environment::reportingSoapUrl()` and
`Environment::restBaseUrl()` for `Environment::Production` — are transcribed from
the vendor documentation and have **never returned a byte**.

That the test pair works is not evidence about the production pair. They are
different hosts, and a wrong one fails at the first live payment rather than in
any test.

### `GetPendingTransactions` has never been called

The probe runs exercised `InitPayment`, `GetPaymentDetails`, `GetBindings`,
`ActivateBinding`, `DeactivateBinding`, `RefundPayment` and `CancelPayment` over
REST, and `GetTransactionList` and `GetProblemTransactions` over SOAP. This
endpoint appears in the API manifest and in no request any probe ever sent.

Its success shape is the manifest's declaration alone — rows carrying `OrderId`
rather than the response-code envelope every other endpoint returns — and its
failure shape is **unobserved entirely**: whether a rejected date range comes
back as a response code, as the ASP.NET fault envelope, or as an empty list is
unknown. The transport gives it no special case for exactly that reason: a
response code is branched on, a `Message` without one is a fault, and a body with
neither is returned as decoded. Inventing a shape for it would be inventing a
contract.

### What an ASP.NET fault means for `GetPaymentDetails` is inferred — and the record contradicts itself

Before the second completed payment described in the closing section,
`GetPaymentDetails` had been called ten times against the sandbox. Four of the
ten — cases P3, P4.1b, P4.3b and P6, all against the payment that completed —
answered HTTP 200 with `ResponseCode "00"` and a fully populated body, and they
are what a *successful* lookup looks like. This entry is about the other six,
every one of them against an order that was registered and never attempted, and
those six split cleanly in two.

Three of them — cases A10, A10.1 and A10.2 — queried a `PaymentID` that
`InitPayment` had registered and nothing had attempted, and each returned
**HTTP 500** with `{"Message":"An error has occurred."}` — the pairing that
makes a 500 a semantic refusal rather than a transport fault.

The other three — cases B2, B4.2 and B4.4 — queried a `PaymentID` in the
**same state** — registered, re-registered, never sent to the payment page — and
returned **HTTP 200** with `ResponseCode "550"` and `Description "System
Error"`. That is a business answer, not a fault.

So registered-but-unattempted does not predict which of the two the gateway
returns, and **what separates the two groups is unknown.** No probe has ever sent
an unknown, foreign or malformed `PaymentID` to that endpoint either, so no
competing cause has been ruled out. The completed payment does not settle it:
its four lookups add a third outcome class rather than distinguishing the first
two, and nothing about a paid order tells you which answer an unpaid one will
draw. Do not read "500 or 550" as exhausting the space, and do not read the four
successes as narrowing the split.

**The second completed payment called this endpoint six more times, through this
package, and landed in all three classes** — populated bodies on L3, L4.1b and
L4.3b; `"550"` on L5.3, which asked about the completed payment with a wrong
password; and the fault on L5.4 and again on L6.3, both against a second order
that had been registered once and never attempted. So the arithmetic above holds
as a statement about the earlier record, and the fault side gains a fourth
member. Two things came out of the two new fault cases, and only one of them is a
finding. The finding: **the fault reaches the response ahead of any credential
verdict**, because L6.3 asked L5.4's question with a wrong password and got the
same fault rather than the `"550"` a rejected credential produced on L5.3 — so a
caller can infer nothing about its credentials from a `GatewayFaultException`,
neither that they were accepted nor that they were rejected. The other is a
**candidate discriminator and explicitly not a finding**: every payment on the
fault side was registered exactly *once*, L5.4's included, while the `"550"` side
is a single payment registered *twice* and then read three times. Nothing has
tested that — no run has deliberately varied the registration count and read the
outcome, and a sample of four against one admits several other readings. The
question this section asks is still open.

A `GatewayFaultException` from `GetPaymentDetails` therefore means the gateway
would not answer, and nothing more may be read into it. In particular it is not
evidence that the payment did not happen, and it is not evidence that it did.
Retrying is not the response — a 5xx from this gateway is indistinguishable from
a client input error. Reconcile later, and release nothing in the meantime.

### `GetPaymentDetails` and `OrderID`: what `verify()` can and cannot protect

This one matters more than its length suggests, because it decides what
`verify()` is actually worth to a merchant. It is kept here, though it is
**closed**, because the argument it records was made under the uncertainty and
the resolution is only legible beside it.

`verify()` round-trips `GetPaymentDetails` for the callback's `PaymentID` and
then cross-checks the order identity in three branches. Read them from
`src/Vpos.php`:

- **`OrderID` is `null`** — the comparison is **skipped**, because a check cannot
  be made against an absent value. The merchant gets *no* order-identity
  protection on this path; `verify()` degrades to a plain `payments()->details()`
  call. A merchant holding their own order record must compare it against
  `$details->orderId` themselves rather than assume `verify()` did.
- **`OrderID` is present but blank**, empty or whitespace-only — the callback is
  **refused**, under its own named condition, before any comparison runs. It
  fails closed and names the real situation: the gateway supplied no order
  identity to check against. Reporting a mismatch here would be a false
  diagnosis, since nothing disagreed.
- **Anything else** — compared **byte for byte**. The blankness test is the only
  place `verify()` trims anything, so `'1001'` and `'01001'` remain different
  orders.

Until the payment page began to render, none of that had ever been exercised —
and the reason is narrower than "the blank shape has been observed", because
neither group of the six failed calls above ever reaches the branch that refuses
a blank.

The three **HTTP 500** responses carry `{"Message":"An error has occurred."}` and
no `OrderID` key at all. The transport raises `GatewayFaultException`; nothing is
hydrated, and no DTO with an `orderId` ever exists.

The three **HTTP 200** responses did come back with `"OrderID":""` — blank,
alongside `"Currency":""`, `"OrderStatus":""` and `"rrn":""`. But each of those
same bodies carries `"ResponseCode":"550"`, and `ResponseCode::isSuccess()`
(`src/Response/ResponseCode.php`) admits exactly three values: `1`, `'1'` and
`'00'`. `"550"` is none of them, so `HttpTransport` throws `$code->toException(…)`
— an `ApiException` — the moment it interprets the body, before hydration. And
`verify()` calls `payments()->details()` **first**, then reads `$details->orderId`
(`src/Vpos.php`). All three therefore raise inside `details()`, and the blank
branch is never entered.

Stated exactly, as it stood then:

- What a **success**-coded `GetPaymentDetails` body put in `OrderID` had never
  been observed **at all** — not populated, not blank, not absent. No probe had
  completed a payment, and every body that carried fields carried a failure code.
- The blank shape *was* seen three times, but only on bodies the transport
  rejects before `verify()` can look at an `OrderID`.
- **The refusal branch had never been reached in the life of this package.**

**What the completed payment settled.** P3 answered `ResponseCode "00"` with
`"OrderID":"4565037"`, and the callback that preceded it (P2) carried the same
`orderID`. So a success-coded body *does* carry a populated `OrderID`; the
byte-for-byte branch was reached, and it passed. P4.1b, P4.3b and P6 repeat it,
and so do L3, L4.1b and L4.3b on a second payment — where the branch was reached
through `verify()` itself rather than through a hand-built lookup. The third
bullet above is still true — the refusal branch has still never been reached —
but now for a better reason: no body has ever arrived blank *and* success-coded.
Read the reach honestly: this is two payments on the same one sandbox client, not
a guarantee about every order the gateway will ever answer for.

That is an argument *for* the design, not against it. The branch guards a shape
nothing has demonstrated and nothing has ruled out: a gateway answering
successfully while naming no order. On that shape the package refuses rather than
compares, because a blank can only be handled two ways and the other one is
worse. Reporting a mismatch would be a false diagnosis — nothing disagreed.
Reading the blank as absent and skipping would hand back a
`PaymentDetailsResponse` that a merchant reasonably believes was cross-checked
when it was not, on the one code path where they would never find out. Failing
loud on an unobserved shape costs an outage if that shape turns out to be
routine; failing quiet costs order-identity protection on every payment the
gateway declines to name, silently, and the merchant learns which of the two
they got from a dispute.

The blank would not be filtered out on the way through, either, if such a body
ever did arrive success-coded: the hydrator returns an empty string as an empty
string, so `""` is never silently converted to `null`. (That same behaviour is
why the typed amounts were `null` on the `"550"` bodies — an `Amount` cannot be
built without a currency, and the currency came back blank there. It is not why
they would be null today: the completed payment carried `"Currency":"051"`. The
raw companion is still the field that can always be read.)

The feared outage did not materialise. The worry was that a completed payment
would also return a blank `OrderID`, in which case `verify()` would refuse every
callback — at the worst possible moment, on a customer returning from a payment
they had already made. On the evidence there is, a paid order names itself. The
refusal branch stays as written: it guards a shape that has still never been
seen, and failing loud on an unobserved shape is the choice this package made
deliberately.

### The payment page's `type` parameter is the weakest-sourced claim here

`paymentPageUrl()` takes an optional third argument, a `PaymentType` that appends
`&type=` to the redirect. It is claimed to select the card form directly: `13`
opening Apple Pay, `5` the Visa/MasterCard/ArCa form, and omitting it leaving the
gateway to detect the device.

**Its only provenance is a third-party Laravel package's documentation.** It is
not in the vendor PDF, and it is not in the API manifest — though absence there
is not itself evidence against it, since the manifest describes REST request
models and the payment page is a browser redirect with no model to describe.

What *is* evidence against it is that nobody has ever tried. Neither a probe nor
a run through this package has ever sent `type`, and it is worth naming the runs
separately, because stating this loosely is what once made two correct sentences
in this document look like a contradiction. Three sets of page traffic are on
record. The payment-page run of 2026-08-23 recorded **six** URLs, every one of
them `?id=…&lang=en` and nothing else — and every one of them answered 302
before a form rendered. The run in which a payment first completed recorded no
page URL at all, so what it passed, `lang` included, is simply not known. And the
second completed payment opened a URL this package built, `?id=…&lang=en`, which
did render and did take a card. Not one of the three carried `type`, and no
response has ever been observed carrying or honouring it. Until
recently no probe *could* have tried, because the payment page never rendered.
That excuse is gone: the page renders, so this is now a claim that is **untested
rather than untestable**, and the run that could settle it has simply not been
made. Every other entry in this section is an observation that came back
inconvenient; this one is a claim that has never been put to the gateway at all.
That is why it is kept out of every runnable example in the README.

**And whether a wrong `type` is harmless is an inference across surfaces, not an
observation.** Unknown request fields are silently ignored by this gateway, which
suggests an unsupported or mistyped `type` changes nothing. But that finding was
made by sending unknown keys in a **JSON request body to the REST API**. The
payment page is a different surface — an ASP.NET page reading a **query string** —
and nothing has been observed about how it treats a parameter it does not
recognise, or a recognised one carrying a payment type the page has no form for.
It could equally render an error page, or redirect to the BackURL with a response
code — which is precisely what that page was observed doing throughout the
outage above, for reasons that had nothing to do with `type`. Do not treat
omitting `type` and passing a wrong one as equally safe.
Settling it needs two requests against the now-working page: the same
`PaymentID` opened with `type=13` and with `type` omitted.

### The five BackURL spellings are pinned exactly, and a rename breaks the callback

`VposCallback` matches `orderID`, `paymentID`, `opaque`, `resposneCode` and
`description` as literal, case-sensitive keys. `paymentId`, `PaymentID`,
`responseCode` and every other case or spelling variant are **not** accepted. A
missing or blank `paymentID` or `orderID` throws at construction; a renamed
optional parameter silently reads `null`.

That is the intended trade — the alternative is a callback handler that keeps
returning success while quietly seeing no identifiers at all — but it is a real
exposure, and it is better named here than discovered in production. If the
gateway ever changes the spelling or the case of `paymentID` or `orderID`, **every
callback throws**, and it throws at the moment a customer is returning from a
payment they have already made. If it renames one of the other three, that value
reads `null` instead.

`resposneCode` is the gateway's own typo, captured from a live redirect and
confirmed in hex, and it is therefore the wire format. "Correcting" it in a future
refactor would not be a cosmetic change but a silent break: the value would read
`null` while the code looked right.

No probe has ever observed the gateway sending any of these keys under a
different spelling, so nothing suggests a rename is imminent. This records what
happens when one arrives, not a prediction that one will.

Two successful callbacks are now on record, and both corroborate the pinning. P2
was the first ever captured, and it mattered because it was the one shape that
had never been seen before: every earlier capture was a failure redirect. All
five keys arrived under the pinned spellings, `resposneCode` included. L2 is the
second, and all five arrived again.

What makes L2 the stronger evidence *for this section specifically* is not the
second sighting but where the keys were read. P2 was a capture read by hand,
whereas L2's query string was handed to `VposCallback::fromQuery()` — so the
exposure this section exists to track, the pinned literal-key matching in this
package, is the thing that met real gateway output and accepted it. A hand-read
capture cannot establish that; it establishes only what the gateway sent.

Two things about those callbacks' *values* belong beside the spellings, because a
key that matches is not the same as a value a merchant can compare. Both
`description` values were `Operation Approved ` — with a **trailing space**,
handed back verbatim rather than trimmed — and L2's arrived byte-identical to
P2's, on a different payment and a different order, so the space is not a one-off
oddity of a single redirect that a later call might tidy up. Two observations is
still two, but they agree. Log that value; never compare against it. And both
carried `paymentID` in **lowercase**, while `InitPayment` had returned the very
same identifier uppercase. This package normalises neither, for exactly the
reason it does not correct `resposneCode`: the case a channel sends is that
channel's wire format. A merchant comparing the two with `===` gets a mismatch
that is not one, and should compare case-insensitively or compare `orderID`
instead.

Neither capture closes this section. A rename would still throw on `paymentID` or
`orderID` and still read `null` on the other three, and nothing in either one
makes that less true.

That case difference used to carry a second and larger cost, and it no longer
does. `verify()`'s own request — a `GetPaymentDetails` for the callback's
lowercase `PaymentID` — had never been made against the gateway, because every
recorded lookup had sent the uppercase form `InitPayment` returns; the one
request this method ever makes was the one request nobody had tried. **It has now
been made.** Case L3 sent a callback's lowercase `PaymentID` for a payment
`InitPayment` had issued in uppercase, and the gateway answered HTTP 200, `"00"`
and that payment's fully populated body, carrying the `orderID` the callback had.
Read that as exactly what it is — **the lowercase form is accepted** — and not as
case folding, which is a mechanism nobody has observed: only the two forms the
gateway itself issues have ever been sent to that endpoint, never a mixed-case
one.

---

## Where the evidence lives

`docs/api-reference/api-surface.json` is the specification of record: scraped
once from the live ASP.NET Help pages, which are reflected from the bank's own C#
models, and committed so that any claim about the API is checkable without asking
anyone for a document. It is committed for versioning but `export-ignore`d, so
it lives in the repository and not in the installed package. It is not refreshed
automatically; if the upstream API is suspected to have moved, re-run the scraper
by hand and read the diff.

**Seven test files read it at test time**, each holding the path in a private
`MANIFEST` constant, loading it with `file_get_contents()` and asserting that the
read succeeded: `tests/Client/ClientOperationTest.php`,
`tests/Enum/PaymentTypeTest.php`, `tests/Exception/ExceptionHierarchyTest.php`,
`tests/Http/RedactorKeySetTest.php`, `tests/Request/RequestContractTest.php`,
`tests/Support/ManifestConformanceTest.php` and
`tests/Support/NoSensitiveManifestFieldInMessageTest.php`. Four more —
`tests/Callback/VposCallbackTest.php`, `tests/Client/PublicSurfaceTest.php`,
`tests/Config/EnvironmentTest.php` and `tests/VposTest.php` — cite it in prose
only and would not notice its absence.

That is why the manifest is committed rather than treated as a working note, and
why it lives under `docs/` rather than beside the development scratch. This was
measured rather than predicted: with the manifest moved and nothing else changed,
the suite exited 2 with **7 errors and 36 failures**, and only **937 of the 1014
tests the suite then held reached the runner at all**, because an error in a
`beforeClass` fixture aborts the remainder of its class. The assertion count was
the sharpest signal — **4,956 against a healthy 90,632.** Those are the figures
as they stood when the experiment was run; the suite has grown since, and the
current totals are given at the end of this document. The proportions are what
the experiment established, not the absolute numbers. The failing classes were exactly the seven named
above, no more and no fewer, and the four prose-only files stayed green.

So an unreadable manifest is not a degraded run; it is most of the suite not
executing. It is also not a generated artifact that can be recreated on demand —
the scraper is a one-shot against Help pages that may not still be there — so the
manifest moves when the repository is reorganised, and it never disappears.

**The gateway evidence is a different matter, and this is the honest version of
it.** Every claim in this document about how the vPOS service behaves was
established empirically: a real request was sent to the live sandbox and the
response was recorded at the time. Those experiments are labelled by phase and
number — **A** the unattended REST run, **B** an interactive one built around a
real browser payment, **C** the SOAP binding and amount encoding, **P** the run
in which a payment finally completed, and **L** the second completed payment,
which is also the first run in which every request was built and sent by this
package rather than by a hand-rolled probe — with a case number inside each phase
and a sub-number where a case needed variants. **P** and **L** are the two
letters that are not sequence positions: each is a single run, P a run of ten
records numbered P1 to P6 and L a run numbered L1 to L7, with sub-numbers where
a case needed a follow-up read — P4.1 is a refund and P4.1b is the lookup that
measured its effect. Both are set out in the closing sections below. That is
what a docblock in `src/` means when it cites `probe A7.1`, `probe B2`, `P3` or
`L1`:
one recorded request and its response, sent to settle one specific ambiguity. The
recordings themselves are working material and are not published — they are not
in this repository and they do not ship in the Composer distribution, so the
label names the experiment and not a file you can open.

So a reader cannot check what this document says about the bank. Where an earlier
section states that a response came back with a field blank, that
`GetPaymentDetails` was called ten times before a second payment ran and four of
those ten succeeded, or that a
code arrived as an integer from one endpoint and a string from another, that is
reported on this document's authority and nothing else. The package half remains
fully checkable — `src/` and `tests/` are in front of you, and every structural
claim above was written by reading them — but the gateway half is testimony.

That is a real loss of checkability and it should not be papered over. It is also
why the epistemic markers throughout this document matter more than they would if
the evidence shipped beside them: when a reader cannot inspect the record, the
distinction between **observed**, **inferred** and **asserted** is the only thing
carrying the weight. It has been maintained deliberately, and at some cost to the
prose. Where a sentence says something was observed, it was observed. Where it
says something is an inference, no observation supports it. Where it says a claim
is corroborated by nothing in this repository, that is exactly what is meant, and
it is a warning rather than a turn of phrase. Read those words as load-bearing,
because with the record unpublished they are the whole of the guarantee.

At the time of publication line coverage is 100.00% and the mutation score is
100. Both are floors rather than measurements: `composer coverage:check` exits
non-zero below 100.00%, and `minMsi` and `minCoveredMsi` are both set to 100, so
neither can slip without failing the build. There is no static-analysis
baseline, no coverage-ignore annotation and no mutation-ignore annotation
anywhere in `src/`.

The exact test, assertion, statement and mutant counts are deliberately not
quoted here. They move with every test added, and an earlier revision of this
paragraph quoted a tuple that was stale by one test before it shipped — a
snapshot that needs correcting every time the suite grows is not a snapshot but
an unmaintained live claim. `composer test`, `composer coverage` and
`composer infection` print the current figures, and the floors above are what
those runs are checked against.

---

## The payment that completed

On 2026-08-26, after the whole of this package had been built against a sandbox
whose payment page would not render, the page rendered. A real card was charged,
the payment was read back in full, refunded twice, and then refused twice — once
for asking too much and once for asking for the wrong thing. This section is the
narrative; `CONVENTIONS.md` §4.14 through §4.23 is the normative version, and it
is the file the markers in `src/` point at.

The run produced ten records:

| Case | Operation | Outcome |
|---|---|---|
| P1 | `InitPayment` | HTTP 200, `ResponseCode` `1`, `"OK"` |
| P2 | the BackURL callback | `resposneCode=00` — the first successful one ever captured |
| P3 | `GetPaymentDetails` | HTTP 200, `"00"`, a fully populated body |
| P4.1 | `RefundPayment`, 4 of 10 | `"00"`, `"Success"` |
| P4.1b | `GetPaymentDetails` | the amounts after one refund |
| P4.3 | `RefundPayment`, 3 more | `"00"`, `"Success"` |
| P4.3b | `GetPaymentDetails` | the amounts after two |
| P4.5 | `RefundPayment`, more than the balance | refused, `"07"` |
| P5 | `CancelPayment` on a refunded payment | refused, `"07"` |
| P6 | `GetPaymentDetails` | final state |

**The finding most likely to catch a merchant is about `Description`.** P1 sent
one; P3 returned it in **`TrxnDescription`** and put the processor's own wording
in `Description` — `Approved. - Payment post authorized`, becoming
`Approved. - Refunded payment back to client card` once the refunds had run. The
symmetry a caller expects is simply false: you set `Description` and you read
`trxnDescription`. Both are declared `string` in the manifest with no semantics
attached, and the vendor PDF calls both "description of the transaction", so
nothing but this run distinguishes them.

**Three amount fields, and only one of them is a stable comparand.** Across P3,
P4.1b and P4.3b/P6, `ApprovedAmount` held at 10.0 while `DepositedAmount` went
10.0 → 6.0 → 3.0 and `RefundedAmount` went 0.0 → 4.0 → 7.0. `DepositedAmount` is
the **remaining refundable balance**, not the captured total, and `Approved =
Deposited + Refunded` held on every body. That corrected shipped guidance in
`README.md`, which had told merchants to compare `depositedAmountRaw` against
the amount they charged — advice that is right up to the first refund and wrong
after it. It also closed, rather than deferred, the question of whether `Amount`
needs arithmetic: the gateway publishes all three quantities, so there is nothing
for a local subtraction to work out.

**`OrderStatus` and `PaymentState` are two fields, not two spellings of one.**
This document asked, in several places and for the whole of the outage, whether
the wire carried `"2"` **or** `"payment_deposited"`. The question was malformed:
it carries both, in the same body, in two
declared fields, each hydrating to its own enum. And `"4"` /
`payment_refunded` appeared on a payment that was only **partially** refunded,
with 6.0 still outstanding — so the refunded status does not mean fully
refunded, and a merchant who reads the status and skips the amounts will get it
wrong.

**`"07"` means two unrelated things.** P4.5's over-refund and P5's impossible
reversal both answered `"07"`, distinguished only by `ResponseMessage`. That is
the strongest evidence yet for two decisions this package had made on argument
alone: no response code is mapped to a dedicated exception subclass, and no
code-to-description table ships, because the vendor PDF calls `07` "System
Error" and a transcribed table would have printed that over the top of two
accurate messages the gateway had already sent. The success message varies by
endpoint in the same way — `"OK"` from `InitPayment`, `"Success"` from
`RefundPayment` — so any local table would have had to invent one of the two.

**Formats and shapes, all first observed on P3 and unchanged afterwards.**
`DateTime` is `d/m/Y H:i:s`, which is neither ISO 8601 nor the format the
reporting endpoints use; this package carries it as text and does not parse it.
`ExpDate` is `Ym`. `rrn` is a UUID and is byte-identical to `MDOrderID` on every
body, so reconciling on both is reconciling on the same value twice. Absent
values arrive as **empty strings, never null** — four fields did — and the
hydrator passes an empty string through as an empty string, converting in
neither direction. `BankInfo` is present and partially populated: the country
code and country name came back, `BankName` did not. `PaymentType` arrives as an
integer. `MerchantId` and `TerminalId` carry the lowercase `d` the manifest
declares, now confirmed on the wire. `ApprovalCode` and a pre-masked
`CardNumber` both carry real values for the first time in this project's
evidence, which is why the security obligations in `CONVENTIONS.md` §6 are now
live rather than anticipatory — and why no value from either field appears
anywhere in this document.

**Two fields turned out to name a person, and neither name says so.**
`ClientName` holds the **cardholder's** own name, not the merchant's;
`ProcessingIP` holds the address the cardholder paid from, not the merchant's
server's. The manifest carries no semantics to correct either reading. Both were
added to the redactor's wholesale-replacement set as a direct result.

**A rejected write answers at read speed.** P4.5 and P5 both came back in about
102 ms — faster than every `GetPaymentDetails` in the run, which took 145–174 ms
— while the two refunds that actually moved money took 518 ms and 910 ms, and
`InitPayment` took 61 ms. "Writes are slower than reads" is the wrong summary:
the cost is in the settlement, not in the verb. The practical consequence is a
timeout one, and it is in `README.md`: the slowest operations here are exactly
the ones this package will never retry, so a timeout short enough to cut one off
buys an `IndeterminateStateException` and a manual reconciliation rather than a
second attempt.

**That ordering did not survive the second payment, and `CONVENTIONS.md` §4.21
now forbids restating it as a rule.** On the second run the two refusals were the
*slowest* calls in their own group — 66 ms and 69 ms against reads of 13 to 47 ms
— because read latency itself had moved: the same endpoint answered in 170 to
216 ms during the refunds and in 13 to 25 ms minutes later, on the same sandbox
client. So "a refusal comes back faster than a read" was true of one run and
false of the next. What survives both runs is only the shape — a refused write
costs about what a read costs, a write that settles costs several times more —
and the timeout consequence above, which depends on the shape and not on the
ordering. Two runs on one sandbox: orders of magnitude, never a budget.

**Not one manifest declaration was contradicted.** Every field the wire returned
matched the API surface manifest, every type matched, and no undeclared key
appeared on any record at any endpoint — `PaymentDetailsResponse` declares thirty
fields and thirty came back. The specification of record held against a live
payment for the first time. Where this run corrected something, what it corrected
was a **document**, never the manifest.

**What this run did not establish is set out in the closing section below**,
alongside the second payment that followed it a day later. That section, and not
this one, is where the caution against over-reading a completed payment lives,
because after a second run the caution has to be read against both.

---

## The second payment, and the first bytes this package ever sent

On 2026-08-27 the sandbox carried a second payment end to end, and it answered a
different question from the first. P1 through P6 were hand-built: a probe script
composed the JSON and read the responses, so what they established was how the
*gateway* behaves. The second run — cases **L1** to **L7**, set out normatively
in `CONVENTIONS.md` §4.24 to §4.27 — sent nothing by hand. Every request in it
was built by a request DTO from `src/Request/`, serialised and dispatched by
`HttpTransport`, and hydrated back into the response DTOs `src/Response/`
declares. 10 AMD, by card, approved, then partially refunded twice, then a set
of deliberate error paths, and finally one operation re-run through a capturing
PSR-3 logger over the real bodies.

**Four claims in this document were claims that the package's own behaviour was
unobserved, and the run falsified all four.** *The bytes it emits had never been
sent:* L1 put `"Amount":"10.00"` on the wire — a quoted decimal string,
hex-confirmed in the captured body, where P1 had sent the JSON integer `10` — and
the gateway answered `ResponseCode` `1`, `"OK"`; L4.1 and L4.3 refunded at
`"4.00"` and `"3.00"` and both answered `"00"`, `"Success"`. `OrderID` went
beside the quoted amount as a bare integer, so the mixed encoding this package
emits is accepted exactly as it stands. *`verify()`'s own request had never been
made:* L3 sent the callback's lowercase `PaymentID` and the gateway returned the
right payment's populated body. *`GetPaymentId` had never been called:* L6.1
called it, and it answered with the identifier in **lowercase**, siding with the
callback against `InitPayment` — a third channel for a case split this document
had recorded as two. And *the reason this document gave for
`JSON_UNESCAPED_UNICODE` was wrong.* It said Armenian `Description` values
round-trip correctly. They do not: Armenian text sent in `Description` comes back
from `TrxnDescription` with each non-Latin codepoint replaced by U+00BF, the
codepoint count and the ASCII prefix preserved. The flag stays and stays
load-bearing — L1's outgoing body carried raw Armenian with no `\u` sequence
anywhere and was accepted — but the justification for it was false. That is now a
`CONVENTIONS.md` §13 entry, and a question with the bank.

**And now the part that matters most, because two completed payments invite
over-reading more than one did.** Read them together and the arithmetic is small.
Two payments, on the **same single** sandbox client, both 10 AMD, both by card,
both approved, both partially refunded twice, both on the **test** environment.
One merchant, one card, one currency, one gateway environment. A second
successful run doubles the record and widens nothing: it is a stronger reason to
trust the paths it covered, and no reason whatsoever to trust the paths it did
not. Nothing below moved because of it.

What neither run reached is held above, entry by entry, under **What the sandbox
never confirmed**, and the `@todo unverified` markers in `src/` still point at
those entries. In summary: a real **decline** — both payments were approved. Any
currency but AMD, in either direction. Any **fractional** amount, in either
direction — L1's `"10.00"` settles how this package *encodes* an amount and says
nothing at all about precision, because every fraction either run sent was `.00`,
so the amount-precision entry above is untouched. Any binding operation, and
`ConfirmPayment`: the sandbox client is entitled to neither, which also means a
wrong password has never reached a binding endpoint, so whether one would answer
`"20"` for a credential rejection is not merely untested but **structurally
unobservable** on that client — and `AuthenticationException` is meanwhile
unreachable outside `InitPayment`. Any call to `GetPendingTransactions`. Either
production host, neither of which has ever returned a byte. The SOAP surface,
which v1.0 does not ship. A duplicate-order rejection. And the payment page's
`type` parameter, still untested rather than untestable.

**One claim about the payment page needs the run named**, because stating it
loosely is what once made two correct sentences here look contradictory. The
`lang` parameter is exercised for **`en` only, and only by the second run**. The
payment-page run of 2026-08-23 recorded six URLs, all carrying `lang=en`, and
every one of them answered **302 before a form rendered**; the record of the
first completed payment holds no page URL at all, so which `lang` it passed — if
any — is not known. **L2 is the first `lang=en` page load that rendered a form
and took a card.** Even there, what the page rendered *in* was not recorded, so
`en` is confirmed **harmless** rather than confirmed to select English, and `am`
and `ru` have never been sent. Where an earlier section says the six recorded
payment-page requests all carried `lang=en`, it is speaking about that first run
and about nothing else.

A gateway that has answered twice correctly has answered twice.
