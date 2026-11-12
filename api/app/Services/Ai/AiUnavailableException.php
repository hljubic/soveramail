<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The local model server is not reachable / not configured.
 */
class AiUnavailableException extends RuntimeException {}
