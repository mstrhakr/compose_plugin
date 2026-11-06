<?php

require_once __DIR__ . '/ContainerMetadataCache.php';

header('Content-Type: application/json');
try {
    echo json_encode(ContainerMetadataCache::read(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (RuntimeException | JsonException $e) {
    composeLogger('Unable to load container metadata: ' . $e->getMessage(), null, 'user', 'error', 'metadata');
    http_response_code(500);
    echo json_encode(['result' => 'error', 'message' => $e->getMessage()]);
}
exit;