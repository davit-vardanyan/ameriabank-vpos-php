<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Enum;

/**
 * Transaction currency, as an ISO 4217 numeric code.
 *
 * The gateway carries this as a plain string, not a modelled enum, so the set of
 * members comes from the vendor PDF rather than the API surface manifest.
 *
 * Only AMD ("051") is confirmed accepted — it was sent in 24 requests that
 * returned `ResponseCode` 1, across probe phases A, B, C and the paypage run.
 * "840" (USD) was sent exactly once, in probe A7.4, and that request was
 * rejected with `ResponseCode` 560 by the sandbox's blanket 10-AMD amount
 * rule; it says nothing about currency handling. "978" (EUR) and "643" (RUB)
 * have never been sent at all. USD, EUR and RUB are therefore all
 * PDF-sourced and unverified.
 *
 * No probe has ever sent an alpha code, so "numeric, not alpha" is an
 * inference, not an observation of a rejected alternative. It no longer rests on
 * request acceptance alone, though: `GetPaymentDetails` now echoes the code
 * back, and probe case P3 returns `"Currency":"051"` — a numeric string in a
 * response body, on a payment that settled. See CONVENTIONS.md §4.7 and §13.
 *
 * @todo unverified — see CONVENTIONS.md §13 (USD, EUR, RUB and default())
 */
enum Currency: string
{
    case AMD = '051';
    case EUR = '978';
    case USD = '840';
    case RUB = '643';

    /**
     * ISO 4217 exponent: the number of decimal places in the minor unit.
     *
     * All four are 2. AMD is subdivided into 100 luma; luma are obsolete in
     * circulation and Armenian prices are quoted in whole drams, but the
     * internal representation follows ISO, not custom. Treating AMD as
     * zero-exponent would misread every stored amount by a factor of 100.
     */
    public function exponent(): int
    {
        return match ($this) {
            self::AMD, self::EUR, self::USD, self::RUB => 2,
        };
    }

    /**
     * The SDK's assumed default when Currency is omitted from a request.
     *
     * Not observed. `InitPayment`'s response carries only `PaymentID`,
     * `ResponseCode` and `ResponseMessage`, so *that* endpoint never echoes the
     * currency it defaulted to. Support is circumstantial only: probe A3 omitted
     * `Currency` entirely, sent `Amount: 10`, and passed the sandbox's
     * "must be 10 AMD" rule — the probe script itself flags A3 as an open
     * question. The gateway's own default has never actually been seen.
     *
     * It is now settleable in one exchange, which it was not before.
     * `GetPaymentDetails` *does* echo the currency — probe case P3 returns
     * `"Currency":"051"` — so omitting `Currency` on an InitPayment and reading
     * the details back would show what the gateway chose. P3's payment sent
     * `"051"` explicitly (P1), so it shows only that the echo works. Nothing
     * here changes until that probe is run.
     *
     * @todo unverified — see CONVENTIONS.md §13 (settleable by one probe: omit `Currency` on InitPayment, then read `GetPaymentDetails`)
     */
    public static function default(): self
    {
        return self::AMD;
    }
}
