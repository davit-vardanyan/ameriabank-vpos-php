<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Enum;

/**
 * Payment page interface language, passed as the `lang` query parameter on
 * {base}Payments/Pay.
 *
 * Not a modelled type — it is a URL parameter, so this set comes from the
 * vendor PDF.
 *
 * Unverified, and narrowly so. The payment page renders now: probe cases P1
 * through P6 completed a payment through it. But which `lang` value that run
 * used is not in the record — the probe captured the callback and the API
 * exchanges, not the URL the browser was pointed at — so none of these three
 * spellings has been shown to be accepted, and whether an unrecognised one
 * falls back or fails is equally unknown. Settling it takes three page loads.
 *
 * @todo unverified — see CONVENTIONS.md §13 (which `lang` value the completed payment used is not recorded, so none of the three spellings is exercised)
 */
enum Language: string
{
    case Armenian = 'am';
    case Russian = 'ru';
    case English = 'en';
}
