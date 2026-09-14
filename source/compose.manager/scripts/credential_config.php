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
    $vault = new CredentialVault();
    $directory = $vault->materializeDockerConfig($id);
    $summary = $vault->getCredentialSummary($id);
    // Second line lets callers echo which named credential is in use without parsing the vault themselves.
    $label = trim(preg_replace('/[\r\n\t]+/', ' ', ($summary['name'] ?? '') . ' (' . ($summary['registry'] ?? '') . ')') ?? '');
    echo $directory . "\n" . $label . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}