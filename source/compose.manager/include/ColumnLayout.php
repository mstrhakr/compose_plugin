<?php

/**
 * Shared column layout/visibility helpers for the Compose Manager stack table.
 *
 * Single source of truth for column defaults, visible-order normalization and
 * width-fraction math. Used by:
 *   - Exec.php            (get/save column visibility AJAX handlers)
 *   - ComposeManager.php  (server-rendered <thead> + width CSS vars)
 *   - ComposeList.php     (server-rendered stack rows in the saved order)
 *
 * By resolving the saved layout server-side, the stack table renders in the
 * user's chosen order/visibility on first paint — the client customizer's
 * reapply() then becomes a no-op instead of visibly reordering after load.
 *
 * Server-side helpers in this file also export the client model bootstrap so
 * defaults/labels/weights are sourced from one place (avoid PHP/JS drift).
 */

if (!defined('COMPOSE_COLUMN_PREF_FILE')) {
    define('COMPOSE_COLUMN_PREF_FILE', '/boot/config/plugins/compose.manager/column_visibility.json');
}

if (!function_exists('compose_column_defaults')) {
    /**
     * Default visibility for every toggleable column, per scope.
     * Key order also defines the canonical fallback column order.
     */
    function compose_column_defaults(): array
    {
        return [
            'stack' => [
                'update' => true,
                'containers' => true,
                'uptime' => true,
                'health' => true,
                'cpu' => true,
                'memory' => true,
                'net_io' => false,
                'block_io' => false,
                'description' => true,
                'path' => true,
            ],
            'service' => [
                'update' => true,
                'health' => true,
                'cpu' => true,
                'memory' => true,
                'net_io' => false,
                'block_io' => false,
                'source' => true,
                'tag' => true,
                'net' => true,
                'ip' => true,
                'cport' => true,
                'lport' => true,
            ],
        ];
    }
}

if (!function_exists('compose_column_width_weights')) {
    /**
    * Relative width weights for the stack table.
    * Arrow + icon are fixed-px and excluded here;
     * name and autostart are structural and always visible.
     */
    function compose_column_width_weights(): array
    {
        return [
            'name' => 23,
            'update' => 16,
            'containers' => 8,
            'uptime' => 9,
            'health' => 9,
            'cpu' => 10,
            'memory' => 13,
            'net_io' => 10,
            'block_io' => 10,
            'description' => 14,
            'path' => 12,
            'autostart' => 8,
        ];
    }
}

if (!function_exists('compose_stack_column_meta')) {
    /**
     * Header label + <th> class for each toggleable stack column, in canonical
     * (default) order.
     */
    function compose_stack_column_meta(): array
    {
        return [
            'update' => ['label' => 'Update', 'thClass' => 'col-update'],
            'containers' => ['label' => 'Containers', 'thClass' => 'col-containers'],
            'uptime' => ['label' => 'Uptime', 'thClass' => 'col-uptime'],
            'health' => ['label' => 'Health', 'thClass' => 'col-health'],
            'cpu' => ['label' => 'CPU', 'thClass' => 'cm-advanced col-cpu'],
            'memory' => ['label' => 'Memory', 'thClass' => 'cm-advanced col-memory'],
            'net_io' => ['label' => 'Net I/O', 'thClass' => 'cm-advanced col-net_io'],
            'block_io' => ['label' => 'Disk I/O', 'thClass' => 'cm-advanced col-block_io'],
            'description' => ['label' => 'Description', 'thClass' => 'cm-advanced col-description'],
            'path' => ['label' => 'Path', 'thClass' => 'cm-advanced col-path'],
        ];
    }
}

if (!function_exists('compose_service_column_meta')) {
    /**
     * Display labels for each toggleable service column, in canonical order.
     */
    function compose_service_column_meta(): array
    {
        return [
            'update' => ['label' => 'Update'],
            'health' => ['label' => 'Health'],
            'cpu' => ['label' => 'CPU %'],
            'memory' => ['label' => 'Memory'],
            'net_io' => ['label' => 'Network I/O'],
            'block_io' => ['label' => 'Disk I/O'],
            'source' => ['label' => 'Source'],
            'tag' => ['label' => 'Tag'],
            'net' => ['label' => 'Network'],
            'ip' => ['label' => 'IP'],
            'cport' => ['label' => 'Container Port'],
            'lport' => ['label' => 'LAN IP:Port'],
        ];
    }
}

if (!function_exists('compose_column_client_model')) {
    /**
     * Bootstrap payload consumed by composeColumnCustomizer.js.
     * This keeps labels/defaults/weights in one canonical source.
     */
    function compose_column_client_model(): array
    {
        $stackLabels = [];
        foreach (compose_stack_column_meta() as $key => $meta) {
            $stackLabels[$key] = (string)($meta['label'] ?? $key);
        }

        $serviceLabels = [];
        foreach (compose_service_column_meta() as $key => $meta) {
            $serviceLabels[$key] = (string)($meta['label'] ?? $key);
        }

        return [
            'stackCols' => $stackLabels,
            'serviceCols' => $serviceLabels,
            'defaults' => compose_column_defaults(),
            'stackWidthWeights' => compose_column_width_weights(),
            'stackAlwaysVisible' => [
                'name' => true,
                'autostart' => true,
            ],
        ];
    }
}

if (!function_exists('compose_normalize_column_visibility')) {
    /**
     * Normalize a raw (possibly partial/untrusted) visibility payload into the
     * canonical shape: per-scope booleans plus stackOrder/serviceOrder arrays
     * that contain only visible columns, in a de-duplicated order.
     *
    * Canonical normalization shared by both persisted reads and save requests.
     */
    function compose_normalize_column_visibility($saved): array
    {
        $defaults = compose_column_defaults();
        $normalized = array_merge($defaults, [
            'stackOrder' => array_keys(array_filter($defaults['stack'])),
            'serviceOrder' => array_keys(array_filter($defaults['service'])),
        ]);

        if (!is_array($saved)) {
            return $normalized;
        }

        foreach (['stack', 'service'] as $scope) {
            if (!isset($saved[$scope]) || !is_array($saved[$scope])) {
                continue;
            }
            foreach ($defaults[$scope] as $key => $defaultVal) {
                if (array_key_exists($key, $saved[$scope])) {
                    $rawVal = $saved[$scope][$key];
                    $boolVal = filter_var($rawVal, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($boolVal !== null) {
                        $normalized[$scope][$key] = $boolVal;
                    } elseif (function_exists('composeLogger')) {
                        composeLogger(
                            'Ignoring non-boolean column visibility value',
                            ['scope' => $scope, 'column' => $key, 'valueType' => gettype($rawVal)],
                            'user',
                            'debug',
                            'column-layout'
                        );
                    }
                }
            }
        }

        foreach (['stackOrder', 'serviceOrder'] as $orderKey) {
            $scope = $orderKey === 'stackOrder' ? 'stack' : 'service';
            $allowed = array_keys($defaults[$scope]);
            $savedOrder = isset($saved[$orderKey]) && is_array($saved[$orderKey]) ? $saved[$orderKey] : [];
            $ordered = [];

            foreach ($savedOrder as $col) {
                if (in_array($col, $allowed, true) && $normalized[$scope][$col] && !in_array($col, $ordered, true)) {
                    $ordered[] = $col;
                }
            }
            foreach ($allowed as $col) {
                if ($normalized[$scope][$col] && !in_array($col, $ordered, true)) {
                    $ordered[] = $col;
                }
            }

            $normalized[$orderKey] = $ordered;
        }

        return $normalized;
    }
}

if (!function_exists('compose_read_column_layout')) {
    /**
     * Read and normalize the saved column layout from disk. Falls back to
     * defaults when the preference file is missing or unreadable/invalid.
     */
    function compose_read_column_layout(): array
    {
        $saved = null;
        if (is_file(COMPOSE_COLUMN_PREF_FILE)) {
            $raw = @file_get_contents(COMPOSE_COLUMN_PREF_FILE);
            if ($raw === false) {
                if (function_exists('composeLogger')) {
                    composeLogger(
                        'Failed to read column layout preference file; using defaults',
                        ['file' => COMPOSE_COLUMN_PREF_FILE],
                        'user',
                        'warning',
                        'column-layout'
                    );
                }
            } else {
                $decoded = json_decode((string)$raw, true);
                if (is_array($decoded)) {
                    $saved = $decoded;
                } elseif (function_exists('composeLogger')) {
                    composeLogger(
                        'Invalid column layout JSON; using defaults',
                        [
                            'file' => COMPOSE_COLUMN_PREF_FILE,
                            'jsonError' => json_last_error_msg(),
                        ],
                        'user',
                        'warning',
                        'column-layout'
                    );
                }
            }
        }
        return compose_normalize_column_visibility($saved);
    }
}

if (!function_exists('compose_stack_render_order')) {
    /**
     * Full DOM render order for the toggleable stack columns: visible columns in
     * the saved order first, then any hidden columns in canonical order. Hidden
     * columns are still rendered (so the customizer can reveal them without a
     * reload); the table's hide-col-* classes control their visibility.
     *
    * Client reorder/reapply uses this same visible-first ordering model.
     */
    function compose_stack_render_order(array $layout): array
    {
        $order = isset($layout['stackOrder']) && is_array($layout['stackOrder']) ? $layout['stackOrder'] : [];
        foreach (array_keys(compose_column_defaults()['stack']) as $col) {
            if (!in_array($col, $order, true)) {
                $order[] = $col;
            }
        }
        return $order;
    }
}

if (!function_exists('compose_stack_hidden_columns')) {
    /**
     * List of toggleable stack columns that are currently hidden. Used to emit
     * hide-col-* classes on the table server-side.
     */
    function compose_stack_hidden_columns(array $layout): array
    {
        $hidden = [];
        foreach (array_keys(compose_column_defaults()['stack']) as $col) {
            if (empty($layout['stack'][$col])) {
                $hidden[] = $col;
            }
        }
        return $hidden;
    }
}

if (!function_exists('compose_stack_width_fractions')) {
    /**
    * Compute the per-column width fraction for the stack table.
    * Hidden columns get a
     * fraction of 0. Keys are the weight keys (name, autostart, toggleables).
     */
    function compose_stack_width_fractions(array $layout): array
    {
        $weights = compose_column_width_weights();

        // name + autostart are structural (always visible); toggleables per prefs.
        $visible = ['name' => true, 'autostart' => true];
        foreach (array_keys(compose_column_defaults()['stack']) as $col) {
            $visible[$col] = !empty($layout['stack'][$col]);
        }

        $total = 0;
        foreach ($weights as $col => $weight) {
            if (!empty($visible[$col])) {
                $total += $weight;
            }
        }

        $fractions = [];
        foreach ($weights as $col => $weight) {
            $fractions[$col] = ($total > 0 && !empty($visible[$col])) ? ($weight / $total) : 0.0;
        }
        return $fractions;
    }
}
