<?php

declare(strict_types=1);

namespace Droost\Engine\Wiki;

/**
 * Thrown when a page cannot be composed into a valid managed OKF page.
 *
 * Carries a human-readable reason only — never the page body or any generated
 * content — so it is safe to surface in command output and logs.
 */
final class ComposeException extends \RuntimeException {

}
