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
 * Unverified, and narrowly so — but one of the three spellings is exercised
 * now. Case L2 opened the page at a URL paymentPageUrl() produced carrying
 * `lang=en`: the card form rendered, a card was charged, and the callback came
 * back `resposneCode=00`. Read that narrowly. What the page rendered *in* was
 * not recorded, so `lang=en` is confirmed harmless rather than confirmed to
 * have selected English. Which `lang` the earlier completed payment used —
 * probe cases P1 through P6 — is still not in the record, because that run
 * captured the callback and the API exchanges rather than the URL the browser
 * was pointed at.
 *
 * `am` and `ru` have never been sent, and whether an unrecognised spelling
 * falls back or fails is equally unobserved. Settling the rest takes two page
 * loads.
 *
 * @todo unverified — see CONVENTIONS.md §13 (`am` and `ru` have never been sent; `lang=en` is observed harmless rather than observed to select English)
 */
enum Language: string
{
    case Armenian = 'am';
    case Russian = 'ru';
    case English = 'en';
}
