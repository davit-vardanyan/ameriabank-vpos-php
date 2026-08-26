<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Exception;

use Throwable;

/**
 * Implemented by every exception this package throws.
 *
 * Catching this interface catches everything originating here and nothing else.
 * Every implementation also extends a native SPL exception, so callers who
 * prefer to catch LogicException or RuntimeException still can.
 *
 * The one method is here rather than on the concrete classes because catching
 * this interface is the documented way to catch a failure from this package, and
 * a caller that has done so must be able to ask the question without first
 * narrowing to one of ten types.
 */
interface VposExceptionInterface extends Throwable
{
    /**
     * Whether this object lost a `previous` chain when it was unserialized.
     *
     * Every exception in this package defines `__serialize()`, which drops the
     * chain: a transport failure's `previous` is a PSR-18 exception or a stand-in
     * for one, and both hand back the request they were sent, whose body is the
     * merged credential payload (CONVENTIONS.md §5, §6). Without this flag a
     * restored exception would be indistinguishable from one that never had
     * a cause.
     *
     * Three answers, and they are not interchangeable:
     *
     * - `null` — this object has not been through a round trip. `getPrevious()`
     *   is the authoritative answer, and it is intact.
     * - `false` — this object was restored, and the original carried no cause.
     *   `getPrevious()` is null and that is the truth.
     * - `true` — this object was restored and the original did carry a cause,
     *   which was dropped in transit. `getPrevious()` is null and that is not
     *   the whole truth.
     */
    public function chainDropped(): ?bool;
}
