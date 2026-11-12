<?php

namespace App\Services\Mail;

use RuntimeException;

/** The attachment was screened as dangerous and must never be served, previewed or forwarded. */
class BlockedAttachmentException extends RuntimeException {}
