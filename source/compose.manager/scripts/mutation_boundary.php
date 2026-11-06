<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/include/StackMutationGuard.php';

try {
    if ($argc !== 4 || !in_array($argv[3], ['create', 'renew'], true)) {
        throw new InvalidArgumentException('Expected canonical project, stack folder and pending policy.');
    }
    StackMutationGuard::mutationBoundary($argv[1], $argv[2], $argv[3] === 'create');
} catch (Throwable $e) {
    fwrite(STDERR, 'Unable to record stack mutation: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
