# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Exception hierarchy. Every exception implements `VposExceptionInterface` and
  extends a native SPL class, so `catch (VposExceptionInterface)` catches
  everything from this package and nothing else.
- `IndeterminateStateException`, thrown when a non-idempotent operation fails in
  transport and its outcome is unknown. It is deliberately not a subtype of
  `TransportException`, so a caller catching transport failures to retry cannot
  swallow the one case where retrying may capture or refund twice.
- Mutation testing enforced at MSI 100 via Infection — both `minMsi` and
  `minCoveredMsi` — running in CI on the PHP 8.3 / highest-dependencies job.
- Five enums under `src/Enum/`: `PaymentType`, `Currency`,
  `Language`, `OrderStatus` and `PaymentState`. Unknown wire values degrade to
  `null` rather than throwing: `tryFrom()` is the only entry point and a test
  asserts `from()` appears nowhere in `src/`.
- `PaymentType` is verified against the API surface manifest by test rather
  than by transcription. The test reads `models.PaymentsEnum` from
  `api-surface.json` and asserts the case sets are identical in both
  directions, so upstream drift fails the build the next time the manifest is
  regenerated.
- `Currency::exponent()` returns the ISO 4217 exponent, and
  `OrderStatus` ↔ `PaymentState` convert to one another through exhaustive
  `match` expressions with no `default` arm, so adding a case to one without
  the other is a compile-time error.
- Of the remaining four enums, two are now confirmed on the wire and two are
  not. A completed payment returned `OrderStatus` `"2"` and `PaymentState`
  `payment_deposited` in the same body, then `"4"` and `payment_refunded` after a
  refund, so both members and both spellings are observed. `Language` and the
  USD/EUR/RUB currency codes remain vendor-PDF sourced and keep their
  `@todo unverified` markers against CONVENTIONS.md §13. `"051"` (AMD) is still
  the only currency ever sent, and it is now also the only one ever echoed
  back.
- `Amount` under `src/Money/`: a final readonly value object
  holding an integer minor-unit count and its `Currency`, built only through
  `fromMinorUnits()` or `fromDecimalString()`. No float ever touches a monetary
  value: parsing and formatting are integer and string operations scaled by
  `Currency::exponent()`, and an input carrying more decimal places than the
  currency's exponent allows throws rather than absorbing them — `"10.001"` in
  a two-decimal currency is rejected, and so is `"10.000"`, which loses no
  precision at all.
- `ResponseCode` under `src/Response/`: a final readonly value
  object built through `fromWire()`, holding the raw `int|string` the wire sent
  without narrowing it. The gateway types this field differently per endpoint —
  `int` from `InitPayment`, `string` elsewhere — so integer `20` and string
  `"20"` are kept as the distinct wire values they are, `equals()` compares them
  strictly, and `asString()` preserves a leading zero that arrived as a string.
- `ResponseCode::isSuccess()` fails closed. It admits exactly three forms —
  integer `1`, string `"1"`, string `"00"` — and treats every other code, known
  or unknown, as a failure. Reporting a failure as success is the one
  misclassification that causes an unpaid order to be treated as paid. Of the
  three, integer `1` and string `"00"` have both been observed on the wire —
  `"00"` six times across a completed payment's details, its refunds and its
  final state — so only string `"1"` remains vendor-PDF sourced.
- `ResponseCode::toException()` maps authentication failures to
  `AuthenticationException` and every other failure to `ApiException`, and
  throws `ConfigurationException` if asked to build an exception from a success
  code. Only integer `20` classifies as an authentication failure: it was
  observed on `InitPayment` as `"Incorrect Username and Password"`, whereas
  string `"20"` was observed on the binding endpoints as `"Client payment type
  BindingMainRest is not available"` — an entitlement refusal returned with
  credentials that authenticated in the same run. The two are the same code and
  not the same condition.
- `DeclinedException` and `DuplicateOrderException` are deliberately left
  unmapped: no decline has ever been observed, and probe A5 contradicts the
  PDF's duplicate-order codes directly. The same reasoning governs the string
  `"20"` above — adding a classification later is not a breaking change, because
  everything extends `ApiException`; removing a wrong one later is, because a
  caller catching the subclass silently stops catching. Both classes remain
  catchable types.
- A line-coverage floor of 100%, enforced by `composer coverage:check` locally
  and in CI beside mutation testing. Mutation score alone could not see an
  untested class at all — Infection generates no mutants for uncovered code — so
  the two gates answer different questions and neither substitutes for the other.
- `Credentials` under `src/Config/`, holding the merchant client
  ID, username and password. There is no `password()` accessor and there must
  never be one: the value leaves the object only inside the two request shapes
  the API actually uses, `merchantFields()` and `userFields()`. Anything wanting
  the password in isolation is doing something the transport does not need to
  do. Each field is validated before any field is assigned, and blank means
  empty or whitespace-only.
- Every leak channel on `Credentials` was executed rather than reasoned about,
  and the class docblock records what each one printed. `var_dump()`,
  `print_r()` and `serialize()` are closed by `__debugInfo()` and
  `__serialize()`, which return a fixed redaction marker carrying no length, no
  hash and no derived value. `json_encode()` yields `{}`, and a `(string)` cast
  throws rather than rendering, so that channel does not exist. `var_export()`
  has no interception hook and printed the password verbatim against a plain
  string property; the password is therefore held in a
  `\SensitiveParameterValue`, which closes it. `#[\SensitiveParameter]` keeps it
  out of stack traces.
- `unserialize()` on a serialized `Credentials` throws rather than restoring.
  Since `__serialize()` redacts, a restored object would hold the marker where
  the password belongs and would fail authentication in a way that looks like
  wrong credentials rather than a broken restore. Failing at the point of
  restoration is the honest behaviour.
- `Environment`, a string-backed enum resolving every URL the
  client needs: `restBaseUrl()`, `apiUrl()`, `paymentPageUrl()` and
  `reportingSoapUrl()`. It lives in `Config/` rather than `Enum/` because
  `Enum/` holds types whose values appear on the wire and this one does not.
  `paymentPageUrl()` rejects a blank payment ID, because a failed `InitPayment`
  returns `"PaymentID": ""` and a caller who skipped the response-code check
  would otherwise send a customer to a broken page. Only the test REST base and
  the test SOAP host have been reached by a probe; both production hosts carry
  `@todo unverified` against CONVENTIONS.md §13.
- Request DTOs for the eleven in-scope operations under `Request/`,
  response DTOs and their nested models under `Response/`, and
  `Support\ResponseHydrator` mapping wire arrays to responses. No request DTO
  can carry a credential: `ClientID`, `Username` and `Password` are the
  transport's to inject, and a test asserts no request class can emit one.
- The upstream field names are enforced rather than transcribed. A conformance
  test reads `api-surface.json` at test time and checks three axes: every wire
  key the hydrator reads is declared upstream, every declared field is mapped or
  named in `ResponseHydrator::IGNORED_FIELDS` with a reason, and the hydrator's
  named arguments match each DTO's constructor parameters in both directions.
  That third axis is the only thing that can see a DTO change at all — named
  arguments are invisible to the type system and would otherwise surface as an
  `Error` when a real response arrived. The wire's own spellings are preserved
  exactly: `CardBindingFileds`, `IsAvtive`, lowercase `rrn`, and the `PaymentId`
  / `OrderId` casing variants that two models carry alone.
- Wire values never become enums by force. Every enum-typed response field keeps
  its raw value beside a nullable enum, and `OrderStatus` — declared `string`
  upstream while the enum is `int`-backed — resolves only when the raw is
  entirely numeric. A blind cast would turn `payment_deposited` into `0`,
  reporting a completed payment as unpaid.
- Monetary response fields keep their raw scalar beside a nullable `Amount`.
  When a response carries no recognisable `Currency` the `Amount` stays null and
  the raw is kept, rather than a default currency being stamped on a foreign
  transaction. Both cases are observed rather than hypothetical:
  `GetPaymentDetails` returned `"Currency": ""` next to four decimal fields on a
  payment that never went through, and returned `"Currency": "051"` on one that
  completed. A populated currency is necessary but not sufficient — the
  constructor refuses a zero, so `RefundedAmount` `0.0` still yields a null
  `Amount` beside its raw. Decoding a JSON decimal yields a float, which is converted through a
  decimal string in exactly one place; integers and strings bypass that path
  entirely, so neither can be re-rounded.
- The sensitive-field guard is now derived from the manifest for every field the
  manifest declares, so a new card or identity field appearing upstream is
  covered the next time the manifest is regenerated. A documented residue stays
  hand-maintained for the names a manifest cannot supply: the raw-body channel
  (`body`, `payload`, `raw`, `content`), which names no field but names the thing
  an exception must never carry, and card data this API never declares.
- The mutation-score floor sits at 100 rather than at a conventional 90,
  matching what the suite actually holds. A floor below the tree's real score is
  a silent regression budget: a dead branch of the kind this release removed
  scores around 99, which a floor of 90 would have accepted without a word.
- `HttpTransport` under `Http/`: the PSR-18 transport that takes any
  request object, merges the credential set that request declared, sends it, and
  returns the decoded body once it has found no evidence of failure in it. A
  request DTO still cannot carry a credential — the transport merges `ClientID`,
  `Username` and `Password` at dispatch and refuses a body that already contains
  one.
- Nothing in the transport branches on the HTTP status code. It is read once, to
  build a log record, and travels onward only as diagnostic data. Success,
  failure and fault are decided by the shape of the decoded body, because this
  gateway answers an authentication failure with HTTP 200 and a semantic refusal
  with HTTP 500.
- `Contracts\RequestInterface`, the transport's input contract, implemented by
  all eleven request DTOs: `operation()`, `isIdempotent()`, `requiresClientId()`
  and `toArray()`. `requiresClientId()` is new and its answer is read off the API
  surface manifest rather than reasoned about — the seven operations whose
  request model declares `ClientID` send `Credentials::merchantFields()`, and the
  four addressed by a `PaymentID` (`ConfirmPayment`, `CancelPayment`,
  `RefundPayment`, `GetPaymentDetails`) send `userFields()`.
- `GatewayFaultException`, for the gateway's ASP.NET fault envelope — a body
  carrying `Message` and no `ResponseCode`, observed at both 404 and 500. It is a
  sibling of `ApiException`, not a subclass: `ApiException` means the gateway
  gave a business answer, and a fault means it gave none, so there is no response
  code for the accessor to return and none is invented. It carries the operation,
  the HTTP status and the envelope's generic `Message` text, never the body.
- Retry is decided inside the transport and by nothing a caller can pass. A
  failed attempt is repeated only when the request's own `isIdempotent()` says
  so and the operation is absent from the transport's never-retry list; either
  half alone is enough to stop it. The attempt count is configurable from 1 to 5
  and defaults to 3; which operations retry is not configurable at all. No HTTP
  response is ever retried, whatever its status, because a 500 from this gateway
  is a deliberate refusal, not a transient fault — only a PSR-18 network failure
  is. The body is serialised once before the first attempt and the identical
  bytes are resent, since a repeat `InitPayment` returns the same `PaymentID`
  but overwrites the earlier call's parameters. Backoff is 100 ms, doubling per
  attempt.
- The never-retry half of that decision is the transport's own, and it holds
  whatever a request object claims about itself. `ConfirmPayment`,
  `RefundPayment`, `CancelPayment` and `MakeBindingPayment` are refused a repeat
  by the transport regardless of `isIdempotent()`, because
  `Contracts\RequestInterface` is public surface — a caller may hand in an
  implementation this package did not write — and one wrong `return true` on a
  capture is a defect neither a type checker nor a gateway response would ever
  report. The two sources are applied as a conjunction rather than one replacing
  the other, so drift on either side can only ever make the transport more
  conservative. The list is transcribed from CONVENTIONS.md §4.5 and holds nothing
  else, which is what lets a reader check it against that table line by line;
  `ActivateBinding` and `DeactivateBinding` are state-changing and deliberately
  absent, since their own DTOs already refuse a retry and the conjunction needs
  only one honest no.
- A transport failure on `ConfirmPayment`, `RefundPayment`, `CancelPayment` or
  `MakeBindingPayment` sends exactly one request and raises
  `IndeterminateStateException`, naming the operation and, where the request
  carries one, the `PaymentID` to reconcile with `GetPaymentDetails`.
- PSR-3 logging on every exchange — `debug` for a completed one, `warning` for a
  retry or a fault. Records carry metadata only: operation, URL, status, attempt,
  duration. Every record passes through a `Redactor` that masks by rule rather
  than by transcribed name list, so a sensitive field the bank adds upstream is
  covered the moment it appears in a context; a test reads the manifest and
  asserts exactly that. `CardNumber` keeps first-six/last-four, everything else
  sensitive is replaced wholesale, and no key is ever rewritten.
- Timeouts are not set by this package and cannot be: PSR-18 has no timeout API,
  so bounding a request is the injected client's job. The README documents how
  to configure one on Guzzle and on Symfony's HTTP client, and why `Timeout` in
  `InitPaymentRequest` — a gateway-side payment page lifetime — is an unrelated
  concept that does not bound anything over HTTP.
- `VposExceptionInterface::chainDropped()`. It is declared on the interface, not
  on the concrete classes, so a caller who caught the documented way — `catch
  (VposExceptionInterface)` — gains the method without first narrowing to one of
  ten types. Three answers, and they are not interchangeable: `null` means this
  object has not been through a round trip and `getPrevious()` is authoritative;
  `false` means it was restored and the original carried no cause; `true` means
  it was restored and a cause was dropped in transit. Without the flag those last
  two are the same object, which is the difference between a failure that nothing
  wrapped and one whose cause you can no longer see.
- `Vpos`, the entry point, and `PaymentsClient`, `BindingsClient`
  and `ReportsClient` under `Client/`. All eleven in-scope REST operations are
  now reachable: every layer existed before this and none of them was, since
  `HttpTransport` takes a request object and `ResponseHydrator` takes an array
  and both are `@internal`. `Vpos` is a composition root: it builds one
  transport, hands the same instance to all three clients so they share one
  logger, one redactor and one attempt budget, and holds no mutable state. The
  one method on it that is not wiring or a pure URL — `verify()` — is described
  under `VposCallback`, below. There is no setter and no `with*()`
  reconfiguration; a
  consumer needing two environments constructs two instances.
- `Environment` is a required constructor argument with no default. Defaulting to
  `Test` means a misconfigured deployment talks to the sandbox, silently takes no
  money, and finds out at reconciliation weeks later; defaulting to `Production`
  means a developer who forgets it hits live infrastructure. Naming it has
  neither failure mode.
- Method signatures are settled by a rule read off the API surface manifest
  rather than chosen case by case: a method takes primitives when its request
  model carries two or fewer business fields, credentials excluded, and the
  request DTO otherwise. That puts `payments()->init()` and `bindings()->pay()`
  on DTOs and the other nine operations on primitives, and nobody has to defend
  a case. Money is always an `Amount` and a date always a `DateTimeImmutable`, so
  no caller hands over a float or a preformatted date string; the wire format —
  ISO 8601 with a UTC offset, `DateTimeInterface::ATOM`, as the manifest's own
  sample renders it — stays inside the request DTO that needs it.
- Every method returns a hydrated response DTO, never an array. Hydration stays
  in the client rather than moving into the transport, which is why the transport
  knows nothing about which DTO belongs to which operation.
  `reports()->pending()` returns a `list` of them instead of a response object,
  because that operation alone answers with a bare array of rows and has no
  `ResponseCode` envelope to wrap — no envelope class is invented to hold fields
  the manifest does not declare.
- Nothing on the public surface trusts a callback. The BackURL is unsigned — no
  HMAC, no signature, no shared secret — so anyone can forge `resposneCode=00`,
  and a **server-side round trip** is the only route to a payment's outcome;
  `verify()` is that round trip with the order identity pinned to it.
  No method on any of the four public classes accepts a `resposneCode`, an
  `opaque`, or a `ResponseCode` built from caller input, and the one method that
  accepts a callback at all takes the `VposCallback` marker type and reads two
  identifiers off it. A reflection test asserts that much structurally — over
  every public method of all four classes, three forbidden parameter names
  compared case-insensitively and one forbidden parameter type — rather than
  trusting the convention to keep holding.
- `Vpos::paymentPageUrl()`, the one question this package answers without a
  network call. It lives on the entry point rather than on a client because it
  performs no API call, and it delegates to `Environment::paymentPageUrl()` so
  that the URL shape, the blank-ID refusal and the percent-encoding keep exactly
  one home.
- The README's usage section is a complete lifecycle a merchant can copy:
  register an order, redirect to the payment page, read the outcome with
  `details()`, refund. It states plainly that the callback's parameters are
  unsigned and forgeable, shows no way to read a status out of them, and notes
  that even the `paymentID` is attacker-controlled — so `orderId`,
  `depositedAmountRaw` **and the order's status** must all be checked before
  anything is treated as paid. The first two are matched against the merchant's
  own record; the status is checked by positively recognising a paid value and
  treating anything unrecognised as unpaid. That was written when no paid status
  had ever been seen; one has now — `OrderStatus` `"2"` with `PaymentState`
  `payment_deposited` — and the check is unchanged, because one observation of
  one gateway is not the set of values it may send, and `"4"` has since been seen
  on a payment that was only *partly* refunded. The status check is spelled out separately because a successful
  `details()` call proves only that the gateway answered, not that money moved:
  a forged callback can carry a real `paymentID` for the merchant's own
  registered-but-unpaid order, and what the gateway returns when queried in that
  state is itself unobserved — §13 records two unexplained outcomes for it, an
  ASP.NET fault and `ResponseCode` `"550"`.
- `VposCallback` under `Callback/`, which parses the BackURL
  query into a marker type carrying identifiers only, and `Vpos::verify()`, which
  turns one into a verified `PaymentDetailsResponse`. Those five parameters are
  unsigned — no HMAC, no signature, no shared secret — so anyone who can type a
  URL can send `resposneCode=00` for an order nobody paid for, and nothing on this
  type reports an outcome: no `isSuccess()`, no status, no response code, and no
  way to add one honestly. `resposneCode` and `description` survive as
  `untrustedDiagnostics()`, an array, so that a merchant comparing
  `['resposneCode']` with `'00'` has written down in the expression itself that
  the comparison proves nothing. The route from a callback to an outcome runs
  server-side, and `verify()` is the only one of those with the order identity
  pinned to it: it round-trips `GetPaymentDetails` over the merchant's own
  credentials and, when the response names an `OrderID`, requires it to be
  identical to the callback's — closing the replay of a genuine `PaymentID` from
  a stranger's paid order against a merchant who looks orders up by the
  callback's `orderID` — and refuses outright, under its own message, when that
  `OrderID` arrives blank and so names no order to check against. There is no
  `verify(array $query)` overload, because the type is the checkpoint: it pins
  the five wire spellings exactly and case-sensitively, `resposneCode` typo
  included, and rejects a callback whose `paymentID` or `orderID` is missing or
  blank.
- `tests/ClassFinalityTest.php`, a package-wide guard asserting that every class
  in `src/` is `final`. CONVENTIONS.md
  §5 has always required that, and what was missing until now was not
  enforcement but **package-wide** enforcement: finality was already held per
  directory, by four separate tests. `tests/Exception/ExceptionHierarchyTest.php`
  held `src/Exception/`, `tests/Response/ResponseDtoTest.php` the response DTOs
  its own provider derives, `tests/Callback/CallbackSurfaceTest.php`
  `src/Callback/`, and `tests/Client/PublicSurfaceTest.php` `src/Client/` and
  `src/Vpos.php`. What that left uncovered was `src/Config/`, `src/Http/`,
  `src/Money/`, `src/Request/` and `src/Support/` — and
  `src/Response/ResponseCode.php`, which is not a response DTO and so sat
  unguarded inside a directory that looked covered. `src/Contracts/` and
  `src/Enum/` are on neither list because neither holds a class. Its subject
  list is derived from a **recursive** walk of `src/` rather than written down,
  and declarations are read off a `token_get_all()` token stream rather than
  matched with a regex, so a class cannot escape by living in a new
  subdirectory, and the words `class` and `final` inside a docblock or a string
  literal cannot stand in for a declaration. Interfaces, enums and traits are
  excluded by the declaration check, not by an exemption — enums are implicitly
  final and an interface cannot be final. There is exactly one exemption,
  `src/Exception/ApiException.php`, listed with its reason inline because the
  exception hierarchy is its extension point, and the exemption is itself
  assertion-checked: if `ApiException` ever becomes final the exemption fails
  rather than sitting there as dead permission.
- That guard closes a gap that was demonstrated rather than assumed. Two defects
  on exactly this seam — `Vpos::isSuccessful()` and `Vpos::verifyFromQuery()` —
  went green through the entire gate line, and nothing automated caught either
  one; both were found by hand and closed before either was committed, so
  neither was ever committed and nothing has been released. How far they got is
  the point: the guard covering
  them enumerated its subjects by hand and so silently exempted everything not on
  the list, which means the gate line said yes and only a human reading for
  defects said no. The new guard was proved against a control: with a
  non-final class fixture placed in a **nested** directory under `src/` and only
  the new test removed, the pre-existing 1006-test suite still passed, exit 0.
  The same fixture with the new test present fails, and the fixture was then
  removed and the tree confirmed byte-identical by checksum. A top-level fixture
  would have proved nothing, since a non-recursive walker catches that one too.

### Changed

- **The package has spoken to Ameriabank.** Every claim in this changelog before
  this entry was established either by the API surface manifest or by hand-built
  probe scripts; `src/` itself had only ever talked to a mock HTTP client. A
  second sandbox payment — cases `L1` to `L7` in CONVENTIONS.md §4.24 — was
  carried through its whole lifecycle with every request built by a request DTO,
  serialised and dispatched by `HttpTransport`, and hydrated back into the
  declared response DTOs. 10 AMD, by card, approved, refunded twice, then a set
  of deliberate error paths. Nothing in the public surface changed as a result;
  what changed is that several documented claims about it were wrong.
- The bytes this package emits for a monetary value have now been accepted by
  the gateway. `Amount` reached the wire as a quoted decimal string — `"10.00"`
  on registration, `"4.00"` and `"3.00"` on refunds — where every earlier
  observation had sent a JSON integer, and `OrderID` went beside it as a bare
  integer. The gateway accepted the mixed encoding as it stands. **This settles
  how an amount is encoded and says nothing about precision:** every fraction
  sent was `.00`, and no fractional amount has ever reached the gateway in
  either direction.
- `Vpos::verify()` has made its own request. It sends the `PaymentID` the
  callback carried, which arrives lowercase where registration issues it
  uppercase, and no lookup had ever sent that form. The gateway returned the
  correct payment's fully populated body, so the lowercase form is accepted and
  the exposure CONVENTIONS.md §13 used to carry is gone. Read that as the
  observation and not as case folding — only the two forms the gateway itself
  issues have ever been sent, never a mixed-case one.
- `paymentIdForOrder()` (`GetPaymentId`) has been called for the first time. It
  returns the identifier **lowercase**, siding with the callback against
  registration, which makes a third channel for a case split previously recorded
  as two; and its success `ResponseMessage` is the **empty string**, where
  registration answers `"OK"` and a refund answers `"Success"`. Three endpoints,
  three answers — an empty `ResponseMessage` must not be read as a failure.
- Documentation no longer claims that Armenian `Description` values round-trip.
  They do not: Armenian text sent in `Description` returns from
  `TrxnDescription` with each non-Latin codepoint replaced by U+00BF, the
  codepoint count and the ASCII prefix preserved. `JSON_UNESCAPED_UNICODE`
  stays, and stays load-bearing — the outgoing body carried raw UTF-8 Armenian
  with no `\u` sequence anywhere and was accepted — but the reason given for it
  was false. Only Armenian has been tested, only through
  `Description` → `TrxnDescription`, only on the test environment. **Never use a
  non-ASCII `Description` as a reconciliation key.** See CONVENTIONS.md §4.15
  and §13.
- `isAuthenticationFailure()` now documents a gap it always had. It fires on
  integer `20` only, and string `"20"` is overloaded: six observations carry an
  entitlement refusal from the binding endpoints, and one carries a credential
  rejection from `GetPaymentId`. **`AuthenticationException` is therefore
  unreachable outside registration** — every other endpoint degrades a rejected
  credential to a plain `ApiException`, which still carries the gateway's own
  message. The behaviour is deliberately unchanged: the operation correlates
  with the two meanings without explaining them, and because the sandbox client
  has no binding entitlement, a wrong password has never reached a binding
  endpoint, so the deciding case is structurally unobservable for now. Adding a
  classification later is not a breaking change; removing a wrong one is.
- Latency is no longer described as "a rejected write answers at read speed".
  That ordering held on one run and inverted on the next — refusals at 66 and
  69 ms against reads at 13 to 47 ms, where the same endpoint had measured 145
  to 216 ms minutes earlier. What survives is the shape and its consequence: the
  slowest operations are the ones that must never be retried, so a timeout short
  enough to cut one off buys an `IndeterminateStateException` and a
  reconciliation.
- Evidence for the accepted currency is stated as a sweep rather than a count.
  Every request this project has ever sent that carried a currency carried
  `"051"`, save exactly one. A count was correct on the day it was written and
  read as the whole record the moment another run added requests to it.

- `ValidationException::amountNotPositive()`'s parameter is renamed
  `$minorUnits` → `$minorUnitCount`, to keep it distinct from
  `Currency::exponent()`. "Minor units" previously named both a count and an
  exponent, two quantities that must never be multiplied together. No public
  behaviour changes and the exception has no callers yet.
- Exceptions now serialize with their sensitive state scrubbed instead of being
  refused by the engine. A trace holding a `SensitiveParameterValue` cannot be
  serialized and a trace is part of an exception's default serialized state, so
  every exception the transport threw — an ordinary response code 20 decline
  included — was fatal inside a queue worker, and the fatal named a PHP internal
  class and nothing about payments. Every exception here now defines
  `__serialize()` and `__unserialize()`, which replace that state wholesale. The
  message, the throw site, an argument-free call path, the operation, the response
  code with its original `int|string` type (CONVENTIONS.md §4.3) and the response
  message all survive a round trip. Trace arguments do not, and neither does the
  `previous` chain: for a transport failure that chain is a PSR-18 exception or a
  stand-in for one, and both hand back the request they were sent, whose body is
  the merged credential payload. `chainDropped()` reports whether one was
  removed, so a restored exception says which it is rather than looking like it
  never had a cause.
- Frames are filtered on the way in as well as on the way out — a serialized
  payload is bytes from somewhere — and the filter is a positive one, keeping
  `file`, `line`, `function`, `class` and `type` and dropping every other key,
  so an unknown frame key costs a thinner diagnostic rather than a leak. A
  payload with a missing or wrong-typed key restores a degraded object, an empty
  message or an empty operation, rather than throwing: a `TypeError` inside
  `unserialize()` would reintroduce the fatal this change exists to remove.
  `Credentials` is unchanged and still refuses to restore, because a restored
  credential would authenticate as if the merchant had typed the wrong password,
  whereas a restored decline is still a usable decline.
- `SerializationException::causedByJson()` is derived once at construction rather
  than read from `getPrevious()` at call time, so it answers the same before and
  after a round trip. A flag that changes its answer in transit is worse than no
  flag.
- `Environment::paymentPageUrl()` takes an optional third argument, a
  `PaymentType` appended to the payment page URL as `&type=`, claimed to select
  the card form directly rather than letting the gateway detect the device. It is
  optional and defaults to null, so every existing call is unaffected and the URL
  is byte-identical without it. This is the weakest-sourced claim in the package:
  its provenance is a third-party package's documentation, it is not in the
  vendor PDF and not in `api-surface.json`, and no probe has ever sent it. It
  was previously untestable as well as untested, because the sandbox payment page
  did not render; the page renders now, so the claim is merely untested. It
  carries
  `@todo unverified` against CONVENTIONS.md §13 and is deliberately absent from the
  README's examples.
- v1.0's scope is settled rather than provisional. Eleven REST operations ship,
  counted off the public-surface block: `InitPayment`, `GetPaymentDetails`,
  `ConfirmPayment`, `CancelPayment`, `RefundPayment`, `GetPaymentId`,
  `MakeBindingPayment`, `GetBindings`, `ActivateBinding`, `DeactivateBinding`
  and `GetPendingTransactions`. SOAP reporting and `SSNCheck` do not. That list is a
  statement about what the package exposes and never about what the gateway has
  been observed to do — bindings and `ConfirmPayment` are not permitted on the
  sandbox client and `GetPendingTransactions` has never been called at all, all
  three still catalogued as unverified. All three were once carried as open
  questions; none of them is open any more, and each outcome is now recorded
  where it belongs — the scope and the `SSNCheck` exclusion beside the placement
  rule in §7, the SOAP deferral in §13 as something blocked rather than
  undecided. Ten cross-references that pointed at those questions were
  repointed, and repointed by rewriting: a pointer that resolves correctly while
  still calling the question open is only half fixed.
- §7 now answers a question it had been silent on since its inventory was
  replaced by a placement rule: a class may live in a subdirectory, on the
  condition that every structural guard over `src/` walks the tree recursively.
  The permission and the obligation are deliberately one sentence, so nesting is
  not readable as permitted to a guard that does not recurse — the failure that
  wording prevents is silent, since a guard that stops seeing a directory does
  not fail, it stops guarding.
- The README gains a **Not implemented** section naming `GetTransactionList`,
  `GetProblemTransactions` and `SSNCheck` with a reason each, so a merchant
  evaluating the package learns what is missing from the README rather than from
  a missing method. It sits beside **Unverified behaviour** and says how the two
  differ, because they are not the same claim: not-implemented means there is no
  method, unverified means the method ships and only its confirmation is absent.
  The `GetProblemTransactions` entry states what its own reasoning rests on —
  `api-surface.json` is scraped from the REST Help pages and holds no record of
  either SOAP operation, so it can establish that no REST full-list endpoint
  exists but cannot establish that `GetPendingTransactions` is equivalent to
  `GetProblemTransactions`. That equivalence is inferred from the response
  shape, the per-row `ErrorMessage` column, and is not read off the manifest.

- A `CardNumber` that arrived already masked now keeps its first-6/last-4 in a
  log record instead of being replaced wholesale. `Redactor` required 13 to 19
  characters before preserving anything, reasoning that first-6/last-4 on twelve
  characters preserves ten of them. That arithmetic is about a *raw* card
  number, and the gateway never sends one: its masked form is twelve characters
  — first-6, two mask characters, last-4 — so the ten preserved are ten the
  gateway itself published, and the rule was rejecting the only shape this field
  ever takes. §6's promise that the redactor truncates `CardNumber` therefore
  never fired on real data, and every card number in a log read `[redacted]`,
  leaving a support ticket unable to name the card. The floor is now chosen by
  the value: one carrying the gateway's mask character needs only enough length
  for a prefix, a masked character and a suffix, while a value of digits alone
  keeps 13 to 19, because that is where the original arithmetic applies. A value
  that arrived masked now round-trips byte-identical.

- CONVENTIONS.md §6 now records why only `password` is wrapped in
  `\SensitiveParameterValue` while `clientId` and `username` are held in
  cleartext, and `Credentials`' own docblock carries the compressed form. The
  asymmetry was already the code's behaviour and read as an oversight: a reader
  seeing one field of three protected has no way to tell a deliberate boundary
  from a missed one. The identifiers cross the wire in the request body by
  design and neither authenticates anything on its own, so wrapping them would
  advertise a protection the protocol does not provide. The evidence is
  `api-surface.json` rather than prose — across its twelve request models
  `Username` and `Password` are declared on all twelve and `ClientID` on eight,
  which is seven of the eleven operations this package ships. `ClientID` is
  therefore **not** carried on every request: the four models that omit it are
  `PaymentDetailsRequest`, `ConfirmPaymentRequest`, `CancelPaymentRequest` and
  `RefundPaymentRequest`, the operations keyed on a `PaymentID`, which is why
  `Credentials` exposes `merchantFields()` and `userFields()` rather than one
  array. None of the three names appears in any response model.
- The same §6 passage now states that the three controls acting on those fields
  do not cover the same set, because inferring one from another gets it wrong in
  the dangerous direction. `\SensitiveParameterValue` and
  `#[\SensitiveParameter]` cover `password` alone, and `__debugInfo()` and
  `__serialize()` redact `password` alone — so a `var_dump()` of a `Credentials`
  prints the two identifiers in full. The `Redactor` does not, and must not: it
  replaces all three wholesale in every PSR-3 record. "Not a secret" licenses
  leaving an identifier unwrapped in memory; it never licenses writing one to a
  log, and without this stated §6 read as though it did.
- CONVENTIONS.md §13 now defines **two** markers instead of one, and the
  distinction is operational rather than decorative.
  `@todo unverified — see CONVENTIONS.md §13` means the gateway's behaviour has
  never been observed; it waits on a sighting and one observation discharges it.
  `@todo deferred — see CONVENTIONS.md §13` means the behaviour **is** observed
  and the package has deliberately not acted, because the further evidence
  needed to act correctly cannot be obtained yet; it waits on a decision, takes
  two steps to discharge, and **must name the observation that would settle
  it**. That a required observation is currently impossible does not make a
  marker deferred — most markers in `src/` sit behind sandbox entitlements the
  client does not have and are still `unverified`, because what is missing is
  the sighting itself. `ResponseCode::isAuthenticationFailure()` carries the
  tree's first `deferred` marker, naming a wrong password against `GetBindings`
  as the observation that would settle it. Its behaviour is unchanged: only
  integer `20` is an authentication failure, and string `"20"` still reaches the
  caller as a plain `ApiException`. The former closing instruction in §13 was
  replaced by a pointer to the definition rather than left beside it, since a
  rule written down twice drifts and the drifted copy reads as authoritative.
- The `ClientID` example in CONVENTIONS.md §5 and in `Vpos`' docblock is now
  `00000000-0000-0000-0000-000000000000` rather than `'000000'`. The six-digit
  form does not resemble any real value and invited a reader to infer a length
  or numeric constraint that does not exist. The replacement deliberately does
  **not** come with a format claim in the other direction: one sandbox merchant
  is not a specification, and `api-surface.json` types the field `string` with
  no shape, so the surrounding prose says explicitly that the example is not a
  rule.

### Fixed

- The README's credential snippet read `getenv('VPOS_CLIENT_ID')` while
  `.env.example` declares `AMERIA_CLIENT_ID`, and every other variable it
  declares under the same `AMERIA_` prefix. A reader who set up their
  environment from `.env.example` and then copied the README received three
  empty strings and a `ConfigurationException` at construction. The README now
  reads the `AMERIA_` names. Nothing in `src/` reads an environment variable at
  all — the consumer constructs `Credentials` — so neither prefix was ever
  load-bearing; the two documents simply disagreed.

- The README told merchants to verify a payment by comparing
  `depositedAmountRaw` against the amount they charged. `DepositedAmount` is the
  remaining refundable balance and decrements as refunds run — observed going
  10.0 → 6.0 → 3.0 across two partial refunds — so following that instruction
  reported a mismatch for any payment that had been legitimately refunded. The
  stable comparand is `approvedAmountRaw`, which is the authorised total and does
  not move.
- The README described the response's `Description` as the merchant's own
  submitted text. The gateway overwrites that field with processor text
  (`"Approved. - Payment post authorized"`, then
  `"Approved. - Refunded payment back to client card"`) and echoes what the
  merchant sent under `TrxnDescription` instead. A consumer setting `Description`
  and reading it back got the processor's words, not their own. No code changes;
  `TrxnDescription` was already hydrated and is now documented as the field to
  read. That echo is not byte-exact, which this entry did not say and could not
  have: an Armenian `Description` was later observed coming back from
  `TrxnDescription` with every Armenian codepoint replaced by U+00BF, the
  codepoint count and ASCII prefix preserved. Armenian only, `Description` →
  `TrxnDescription` only, test environment. A non-ASCII `Description` is
  therefore not a reconciliation key (CONVENTIONS.md §4.15, §13).

### Security

- `ClientName` and `ProcessingIP` are now redacted from every log record.
  `ClientName` is the cardholder's own name and `ProcessingIP` is the address the
  payment was made from — both personal data, and both arrived populated the
  first time a payment completed. Neither joins §6's never-log list, which names
  card and identity data and is derived by word rule; they are forbidden on a
  personal-data footing instead, and the reason for each is recorded beside it in
  `Redactor`'s rule table. Both rules match on two stems rather than one, because
  a bare `ip` also matches `Description` and `TrxnDescription` — the field
  carrying the merchant's own submitted text — and a bare `name` also matches
  `BankName`, `BankCountryName` and `Username`. The name rule matches
  `cardholder` as well as `client`, so it reaches the spelling it is named after
  and the keys a bridge package writes for itself, which no manifest-derived
  guard can see. One gap is left open deliberately and is named in the class
  docblock rather than gestured at: the cardholder's address is still redacted
  only under a key containing `processing`, so `client_ip`, `ipAddress` and
  `remote_addr` reach a log in the clear. That rules out a shorter stem, not a
  further one — `addr` and `clientip` match no manifest field, so the gap is
  closable under this mechanism and is left open by choice rather than by
  obstacle. A test pins it open so that a
  future fix fails the build and sends whoever makes it back to the paragraph
  that must be rewritten with it.

- The cause attached to a transport failure is now a redacted stand-in. A
  PSR-18 exception hands back the request it was sent, and that request's body
  is the caller's fields merged with the merchant's credentials, so
  `getPrevious()->getRequest()->getBody()` was a password — an explicit read,
  and one that Sentry's PSR-18 integration and Symfony's error-page request
  panel both make. Whether a printer walking the graph reached the same bytes
  depended on the client: Nyholm, Guzzle and Diactoros back a body with a
  resource, which `print_r()`, `var_dump()` and `serialize()` render as a
  handle and stop at, but a client that keeps the payload in its own
  properties, or hands over a string-backed stream, published it in full. The
  stand-in closes both routes. It keeps what a diagnostic needs — the PSR-18
  interface the original implemented, its class name, message, code and call
  site, and the request, with its body scrubbed by the same `Redactor` the log
  records pass through — and drops the original object rather than referencing
  it, because a reference is exactly what those printers follow.
- The stand-in's request carries no headers at all. Headers are a plain array
  property, so unlike a resource-backed body they are walked by every one of
  those printers, and the headers on that request are not this package's: a
  consumer's PSR-18 stack — a Guzzle handler stack, a corporate proxy — may add
  `Proxy-Authorization` or a vendor auth header before failing. Measured, both
  reached `print_r()` on the stand-in verbatim. Redacting them would have been
  worse than dropping them: `Redactor` is keyed on the key and its key set was
  derived from the API manifest's field names, so `X-Api-Password` matches
  while `Authorization` and `Cookie` sail straight through. Nothing diagnostic
  is lost — the transport sets only `Content-Type` and `Accept`, and `Host` is
  the URI's host, which the stand-in still carries beside the method and the
  redacted body.
- Building that stand-in means calling into the client that just failed, so the
  call is guarded. `getRequest()` is third-party code invoked from inside a
  `catch` block, and a client that throws from it — a wrapper that no longer
  holds the request, a client shutting down under it — sent that foreign
  throwable out of `send()` unmapped: `catch (VposExceptionInterface)` caught
  nothing, and a capture that failed in transport lost the
  `IndeterminateStateException` that tells the caller to reconcile rather than
  retry. A failure that refuses inspection now falls back to a bare stand-in
  carrying the original's class name and message and nothing else, so the
  mapping holds whatever the client does.
- Every parameter carrying an encoded request body or a raw response body is
  marked `#[\SensitiveParameter]`, so a stack frame renders it as an empty
  `SensitiveParameterValue` instead of as the payload. With
  `zend.exception_ignore_args` off — the `php.ini-development` default — frames
  keep their arguments and printing an exception printed the body. The
  `JsonException` the engine raises on an encode or decode failure is stripped
  of its trace arguments for the same reason: the payload it choked on lives in
  that trace, and a response body may carry a PAN, an `ExpDate` and an
  `ApprovalCode`.
- That marking also closes the serialization channel, which before it was open:
  `serialize()` on a transport exception succeeded and wrote the password into
  whatever store the exception was on its way to. It is closed by removal rather
  than by refusal — the engine will not serialize a `SensitiveParameterValue`,
  and a trace is part of an exception's default serialized state, so every
  exception here defines `__serialize()` and never offers the trace to the
  serialiser at all. See the `Changed` entry for what a round trip keeps and what
  it drops. The two channels a consumer actually reads an exception through,
  `__toString()` and `getTraceAsString()`, are unaffected.
