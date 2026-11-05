<?php

declare(strict_types=1);

namespace CakePasskeys\Service;

/**
 * The credential is not stored on the server, so the browser may remove the
 * passkey from the authenticator.
 */
class UnknownCredentialException extends WebAuthnException
{
}
