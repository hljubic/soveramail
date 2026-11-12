<?php

namespace App\Services\Mail;

use RuntimeException;

/**
 * The account's OAuth grant lacks gmail.modify; the user has to reconnect.
 */
class SendNotAllowedException extends RuntimeException {}
