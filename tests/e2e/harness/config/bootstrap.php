<?php
declare(strict_types=1);

/**
 * Harness bootstrap intentionally left empty — the real bootstrap work
 * (Configure / ConnectionManager / Cache / Log / Security) happens inline in
 * `public/index.php` so the whole harness stays in one file the reader can
 * scan in one pass. BaseApplication only needs this file to exist.
 */
