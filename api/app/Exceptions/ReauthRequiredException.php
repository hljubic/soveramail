<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when Google no longer accepts our credentials for an account
 * (revoked consent, expired refresh token, changed password ...).
 */
class ReauthRequiredException extends RuntimeException {}
