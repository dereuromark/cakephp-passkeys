<?php

declare(strict_types=1);

namespace Passkeys\Service;

use RuntimeException;

/**
 * Raised by {@see WebAuthnService} for any ceremony failure (challenge missing /
 * expired, attestation invalid, sign-count rollback, per-user cap exceeded, …).
 *
 * Kept as a single class on purpose: callers map this to a generic 4xx without
 * leaking authenticator internals to the browser.
 */
class WebAuthnException extends RuntimeException
{
}
