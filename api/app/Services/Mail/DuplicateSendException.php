<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * The same send request was already accepted or has an ambiguous outcome.
 * It is intentionally never retried automatically because avoiding duplicate
 * mail is more important than pretending an uncertain delivery failed.
 */
class DuplicateSendException extends RuntimeException {}
