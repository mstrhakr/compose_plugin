<?php

/**
 * Canonical Docker Compose project-name sanitizer.
 *
 * Keep this file dependency-free so it can be used from PHP code and shell
 * wrappers without pulling in the full Unraid runtime.
 */
if (!function_exists('compose_manager_sanitize_project_name')) {
    /**
     * @param-out bool $wasEmpty
     */
    function compose_manager_sanitize_project_name(string $rawProjectString, bool &$wasEmpty = false): string
    {
        $wasEmpty = false;
        $sanitizedProjectString = strtolower(trim($rawProjectString));
        $sanitizedProjectString = preg_replace('/[^a-z0-9_-]/', '_', $sanitizedProjectString) ?? '';
        $sanitizedProjectString = preg_replace('/_+/', '_', $sanitizedProjectString) ?? '';
        $sanitizedProjectString = preg_replace('/-+/', '-', $sanitizedProjectString) ?? '';
        $sanitizedProjectString = trim($sanitizedProjectString, '_-');

        if ($sanitizedProjectString === '') {
            $wasEmpty = true;
            return 'compose';
        }

        return $sanitizedProjectString;
    }
}

function compose_manager_is_valid_project_name(string $projectName): bool
{
    return preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $projectName) === 1;
}