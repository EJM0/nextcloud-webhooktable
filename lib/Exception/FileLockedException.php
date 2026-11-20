<?php

declare(strict_types=1);

namespace OCA\WebhookTable\Exception;

/**
 * Die Zieldatei ist gerade gesperrt (z. B. in Nextcloud Office geöffnet).
 * Die Einsendung wird dann zwischengespeichert und später nachgetragen.
 */
class FileLockedException extends \Exception {
}
