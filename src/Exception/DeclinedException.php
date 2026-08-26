<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

/**
 * The transaction was declined by the issuer, the processor, or a fraud rule.
 *
 * Distinct from AuthenticationException: the request was accepted and
 * understood, and the answer was no.
 */
final class DeclinedException extends ApiException {}
