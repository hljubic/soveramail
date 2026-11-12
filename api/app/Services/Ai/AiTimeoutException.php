<?php

namespace App\Services\Ai;

/**
 * Ollama is reachable but the model did not answer in time (a slow machine, a
 * big model, or a long reasoning phase). Kept apart from "unreachable" so
 * callers can degrade instead of telling the user Ollama is not running.
 */
class AiTimeoutException extends AiUnavailableException {}
