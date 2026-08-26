<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

/**
 * Credentials were rejected: response code 20.
 *
 * The gateway returns HTTP 200 for this (CONVENTIONS.md §4.1), so it is
 * detected by response code alone. The code arrives as int 20 from InitPayment
 * and string "20" from every other endpoint.
 */
final class AuthenticationException extends ApiException {}
