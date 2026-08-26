<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

/**
 * The OrderID is already registered.
 *
 * Response codes 01 and 08204 are attributed to this condition by the vendor
 * PDF only. Neither appears in api-surface.json, and neither has been observed
 * on the wire: probe case A5 re-registered an existing OrderID and the gateway
 * answered ResponseCode 1, "OK", returning the PaymentID issued the first time.
 * The trigger for this exception is therefore still unknown.
 *
 * Note that a repeated InitPayment does not normally reach this state. The
 * gateway returns the existing PaymentID with a success code instead
 * (CONVENTIONS.md §4.4), which is why InitPayment is retryable with a
 * byte-identical body.
 *
 * @todo unverified — see CONVENTIONS.md §13
 */
final class DuplicateOrderException extends ApiException {}
