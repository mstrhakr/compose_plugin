<?php

declare(strict_types=1);

require_once '/usr/local/emhttp/plugins/compose.manager/include/CredentialVault.php';

$options = getopt('', ['credential-id:', 'remove:']);
try {
    if (!empty($options['remove'])) {
        CredentialVault::removeDockerConfig((string) $options['remove']);
        exit(0);
    }
    $id = trim((string) ($options['credential-id'] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('Credential ID is required.');
    }
    echo (new CredentialVault())->materializeDockerConfig($id);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}