<?php

namespace App\Services\Imap;

use RuntimeException;

/** Network / TLS / server problem talking to IMAP or SMTP (not a credentials problem). */
class ImapConnectionException extends RuntimeException {}
