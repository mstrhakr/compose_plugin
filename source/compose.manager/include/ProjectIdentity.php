<?php

declare(strict_types=1);

require_once(__DIR__ . '/ProjectNameSanitizer.php');

/**
 * Resolves and pins the Docker Compose project name (`-p`) used for a stack.
 *
 * The original dcflachs/compose_plugin passed the per-stack `name` file to
 * `docker compose -p`, while Compose Manager Plus derives the project name from
 * the (sanitized) project folder and treats `name` as display metadata only.
 * For stacks imported from the original plugin those two values can differ, and
 * silently switching would strand the existing containers, volumes and networks
 * under an abandoned project identity.
 *
 * This class performs a one-time, evidence-based migration:
 *
 *  - Candidates include the archived PHP and event-script naming rules.
 *  - When they differ, live Docker resources labelled
 *    `com.docker.compose.project` decide the winner.
 *  - When exactly one candidate owns resources it is preserved and pinned.
 *  - Confirmed imports retain their legacy name even without labelled resources.
 *  - When multiple candidates own resources, or Docker cannot be probed, the identity is left
 *    UNRESOLVED and every mutating action must refuse to run (fail closed).
 *
 * The winning value and a versioned receipt are persisted so old pins can be
 * audited once without changing later owner choices or display-name renames.
 */
final class ProjectIdentity
{
    /** Per-stack metadata file holding the pinned runtime project name. */
    public const METADATA_FILE = 'project_name';
    public const STATE_FILE = 'project_identity.json';
    public const MIGRATION_VERSION = 1;
    public const LEGACY_REVISION = '074a9af3a29d14b346e5dae8a700a0649cda601c';

    /** Identity read from the pinned `project_name` file. */
    public const SOURCE_PERSISTED = 'persisted';
    /** Folder and legacy `name` candidates agree — nothing to migrate. */
    public const SOURCE_CANONICAL = 'canonical';
    /** Legacy `name`-derived identity owns the live resources and was preserved. */
    public const SOURCE_LEGACY_RUNTIME = 'legacy-runtime';
    /** Folder-derived identity owns the live resources. */
    public const SOURCE_FOLDER_RUNTIME = 'folder-runtime';
    /** Neither candidate owns any Docker resource, so the canonical name is safe. */
    public const SOURCE_UNUSED = 'unused';
    /** Identity could not be proven — mutating actions must fail closed. */
    public const SOURCE_UNRESOLVED = 'unresolved';

    /** Both candidates own live Docker resources. */
    public const CONFLICT_AMBIGUOUS = 'ambiguous';
    /** Docker could not be queried, so absence of evidence proves nothing. */
    public const CONFLICT_PROBE_FAILED = 'probe-failed';
    public const CONFLICT_INVALID_PIN = 'invalid-pin';
    public const CONFLICT_PERSIST_FAILED = 'persist-failed';
    public const CONFLICT_PIN_MISMATCH = 'pin-mismatch';
    public const CONFLICT_SHARED_IDENTITY = 'shared-identity';
    public const CONFLICT_DIRECTORY_MISMATCH = 'directory-mismatch';

    /** @var string Stack directory basename */
    public string $projectFolder;
    /** @var string Sanitized folder-derived candidate (the canonical Plus identity) */
    public string $folderCandidate;
    /** @var string Sanitized `name`-file candidate (the original plugin's identity) */
    public string $legacyCandidate;
    /** @var list<string> Valid identities used by archived and Plus execution paths */
    public array $candidates = [];
    public string $origin = 'unknown';
    /** @var string Effective project name to hand to `docker compose -p` */
    public string $projectName;
    /** @var string One of the SOURCE_* constants */
    public string $source;
    /** @var bool False when the identity is ambiguous; mutating actions must refuse */
    public bool $resolved;
    /** @var string|null One of the CONFLICT_* constants when $resolved is false */
    public ?string $conflictReason = null;
    /** @var array<string, array{containers: int, volumes: int, networks: int}> Per-candidate Docker resource counts */
    public array $evidence = [];

    /** @var callable|null Test/injection hook replacing the live Docker probe */
    private static $probeOverride = null;

    /** @var array<string, array{containers: int, volumes: int, networks: int}>|null Cached probe result for this request */
    private static ?array $probeCache = null;

    /** @var bool Whether $probeCache holds a completed probe (null result means Docker was unreachable) */
    private static bool $probeCached = false;
    /** @var array<string, list<string>> */
    private static array $projectDirectories = [];
    /** @var array<string, list<list<string>>> */
    private static array $projectFileLists = [];
    /** @var array<string, bool> */
    private static array $missingFileEvidence = [];

    private function __construct()
    {
        $this->projectFolder = '';
        $this->folderCandidate = '';
        $this->legacyCandidate = '';
        $this->projectName = '';
        $this->source = self::SOURCE_UNRESOLVED;
        $this->resolved = false;
    }

    /**
     * Replace the live Docker probe (tests, or callers with pre-fetched data).
     *
     * The callable must return a map of project name => resource counts, or
     * null when Docker could not be queried at all.
     *
     * @param callable|null $probe
     */
    public static function setProbe(?callable $probe): void
    {
        self::$probeOverride = $probe;
        self::clearProbeCache();
    }

    /** Drop the memoized probe result (call after Docker state changes). */
    public static function clearProbeCache(): void
    {
        self::$probeCache = null;
        self::$probeCached = false;
        self::$projectDirectories = [];
        self::$projectFileLists = [];
        self::$missingFileEvidence = [];
    }

    /**
     * Resolve the effective runtime project identity for a stack.
     *
     * Pins the result to `project_name` whenever it can be proven, so the
     * Docker probe only ever runs once per stack.
     *
     * @param string      $stackPath     Absolute path to the stack directory
     * @param string      $projectFolder Stack directory basename
     * @param string|null $displayName   Raw contents of the `name` metadata file
     */
    public static function resolve(string $stackPath, string $projectFolder, ?string $displayName): self
    {
        $lock = @fopen(rtrim($stackPath, '/') . '/project_identity.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $identity = new self();
            $identity->projectFolder = $projectFolder;
            $identity->folderCandidate = compose_manager_sanitize_project_name($projectFolder);
            $identity->projectName = $identity->folderCandidate;
            $identity->conflictReason = self::CONFLICT_PERSIST_FAILED;
            return $identity;
        }
        try {
            return self::resolveLocked($stackPath, $projectFolder, $displayName);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function resolveLocked(string $stackPath, string $projectFolder, ?string $displayName): self
    {
        $identity = new self();
        $identity->projectFolder = $projectFolder;
        $identity->folderCandidate = compose_manager_sanitize_project_name($projectFolder);
        $identity->origin = self::hasLegacyOrigin() ? 'dcflachs' : 'unknown';

        $rawLegacy = trim($displayName ?? $projectFolder);
        if ($rawLegacy === '') {
            $rawLegacy = $projectFolder;
        }
        $identity->legacyCandidate = strtolower(str_replace(['.', ' ', '-'], '_', $rawLegacy));
        $identity->candidates = self::candidateNames($projectFolder, $rawLegacy);

        $pinned = self::readPinned($stackPath);
        if ($pinned !== null) {
            if (!compose_manager_is_valid_project_name($pinned)) {
                $identity->projectName = $identity->folderCandidate;
                $identity->conflictReason = self::CONFLICT_INVALID_PIN;
                return $identity;
            }
            $identity->projectName = $pinned;
            $identity->source = self::SOURCE_PERSISTED;
            $stateRaw = @file_get_contents(rtrim($stackPath, '/') . '/' . self::STATE_FILE);
            $state = is_string($stateRaw) ? json_decode($stateRaw, true) : null;
            if (is_array($state) && ($state['version'] ?? null) === self::MIGRATION_VERSION
                && ($state['projectName'] ?? null) === $pinned
                && in_array($state['origin'] ?? null, ['dcflachs', 'plus', 'unknown'], true)
                && is_string($state['source'] ?? null)) {
                $identity->origin = $state['origin'];
                $identity->resolved = true;
                return $identity;
            }
            $identity->candidates = array_values(array_unique([...$identity->candidates, $pinned]));
        }

        if (count($identity->candidates) === 1) {
            $identity->projectName = $pinned ?? $identity->folderCandidate;
            $identity->source = $pinned === null ? self::SOURCE_CANONICAL : self::SOURCE_PERSISTED;
            $identity->persist($stackPath);
            return $identity;
        }

        $probe = self::probe();
        if ($probe === null) {
            $identity->projectName = $pinned ?? $identity->folderCandidate;
            $identity->source = self::SOURCE_UNRESOLVED;
            $identity->conflictReason = self::CONFLICT_PROBE_FAILED;
            return $identity;
        }

        $owners = [];
        foreach ($identity->candidates as $candidate) {
            $identity->evidence[$candidate] = self::countsFor($probe, $candidate);
            if (self::hasResources($identity->evidence[$candidate])) {
                $owners[] = $candidate;
            }
        }

        if (count($owners) > 1) {
            $identity->projectName = $pinned ?? $identity->folderCandidate;
            $identity->source = self::SOURCE_UNRESOLVED;
            $identity->conflictReason = self::CONFLICT_AMBIGUOUS;
            composeLogger(
                "Ambiguous compose project identity for '$projectFolder'; mutating actions are blocked until an identity is chosen",
                ['candidates' => $identity->candidates, 'evidence' => $identity->evidence],
                'user',
                'warning',
                'identity'
            );
            return $identity;
        }

        if (count($owners) === 1 && !self::matchesResourceDirectory($stackPath, $owners[0])) {
            $identity->projectName = $pinned ?? $identity->folderCandidate;
            $identity->source = self::SOURCE_UNRESOLVED;
            $identity->conflictReason = self::CONFLICT_DIRECTORY_MISMATCH;
            return $identity;
        }

        if ($pinned !== null && count($owners) === 1 && $owners[0] !== $pinned) {
            $identity->projectName = $pinned;
            $identity->source = self::SOURCE_UNRESOLVED;
            $identity->conflictReason = self::CONFLICT_PIN_MISMATCH;
            return $identity;
        }

        if ($pinned !== null) {
            $identity->projectName = $pinned;
            $identity->source = self::SOURCE_PERSISTED;
        } elseif (count($owners) === 1) {
            $identity->projectName = $owners[0];
            $identity->source = $owners[0] === $identity->folderCandidate
                ? self::SOURCE_FOLDER_RUNTIME
                : self::SOURCE_LEGACY_RUNTIME;
        } else {
            $identity->projectName = $identity->origin === 'dcflachs'
                && compose_manager_is_valid_project_name($identity->legacyCandidate)
                ? $identity->legacyCandidate
                : $identity->folderCandidate;
            $identity->source = self::SOURCE_UNUSED;
        }

        $identity->persist($stackPath);
        composeLogger(
            "Compose project identity '{$identity->projectName}' for '$projectFolder' ({$identity->source})",
            ['candidates' => $identity->candidates, 'evidence' => $identity->evidence, 'resolved' => $identity->resolved],
            'user',
            'info',
            'identity'
        );

        return $identity;
    }

    public static function hasLegacyOrigin(): bool
    {
        $originFile = defined('COMPOSE_LEGACY_ORIGIN_FILE')
            ? COMPOSE_LEGACY_ORIGIN_FILE
            : '/boot/config/plugins/compose.manager/legacy-origin';
        return is_file($originFile);
    }

    private static function candidateNames(string $projectFolder, string $rawLegacy): array
    {
        $startupWords = preg_split('/[\x20\t\r\n]+/', $rawLegacy) ?: [$rawLegacy];
        $startupCandidate = strtolower($startupWords[0]);
        $startupCandidate = preg_replace('/\./', '_', $startupCandidate, 1) ?? '';
        $startupCandidate = preg_replace('/-/', '_', $startupCandidate, 1) ?? '';
        return array_values(array_unique(array_filter([
            compose_manager_sanitize_project_name($projectFolder),
            strtolower(str_replace(['.', ' ', '-'], '_', $rawLegacy)),
            compose_manager_sanitize_project_name($rawLegacy),
            $startupCandidate,
            strtolower(str_replace(' ', '_', $rawLegacy)),
        ], 'compose_manager_is_valid_project_name')));

    }

    /**
     * Apply an explicit owner decision and pin it.
     *
     * @param string $stackPath Absolute path to the stack directory
     * @param string $choice    Must be one of this identity's candidates
     *
     * @throws \RuntimeException When the choice is not a candidate or cannot be written
     */
    public function chooseIdentity(string $stackPath, string $choice): void
    {
        $choice = trim($choice);
        $lock = @fopen(rtrim($stackPath, '/') . '/project_identity.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException("Failed to lock project identity for '$this->projectFolder'");
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException("Failed to lock project identity for '$this->projectFolder'");
            }
            $current = self::resolveLocked($stackPath, $this->projectFolder, $this->legacyDisplayName($stackPath));
            self::clearProbeCache();
            if (self::probe() === null) {
                throw new \RuntimeException('Docker could not be queried. Project identity was not changed.');
            }
            if ($current->conflictReason === self::CONFLICT_SHARED_IDENTITY) {
                throw new \RuntimeException($current->getMessage());
            }
            if (!in_array($choice, $current->candidates, true)) {
                throw new \RuntimeException("Project identity '$choice' is not a candidate for '$this->projectFolder'");
            }
            if (self::hasCompetingClaim($stackPath, $choice)) {
                throw new \RuntimeException('Another stack folder also claims this project identity.');
            }
            if (!self::pin($stackPath, $choice, 'owner', $current->origin)) {
                throw new \RuntimeException("Failed to persist project identity for '$this->projectFolder'");
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->projectName = $choice;
        $this->source = self::SOURCE_PERSISTED;
        $this->resolved = true;
        $this->conflictReason = null;
    }

    /**
     * Human-readable summary for the migration preview and UI warnings.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'projectFolder' => $this->projectFolder,
            'projectName' => $this->projectName,
            'folderCandidate' => $this->folderCandidate,
            'legacyCandidate' => $this->legacyCandidate,
            'candidates' => $this->candidates,
            'origin' => $this->origin,
            'migrationVersion' => self::MIGRATION_VERSION,
            'canChoose' => in_array($this->conflictReason, [self::CONFLICT_AMBIGUOUS, self::CONFLICT_INVALID_PIN, self::CONFLICT_PIN_MISMATCH, self::CONFLICT_DIRECTORY_MISMATCH], true),
            'source' => $this->source,
            'resolved' => $this->resolved,
            'conflictReason' => $this->conflictReason,
            'evidence' => $this->evidence,
            'message' => $this->getMessage(),
        ];
    }

    /** Explain the current state in words suitable for the WebGUI. */
    public function getMessage(): string
    {
        switch ($this->conflictReason) {
            case self::CONFLICT_AMBIGUOUS:
                return 'Multiple candidate project names own live Docker resources. '
                    . 'Choose which one this stack should use before running any action.';
            case self::CONFLICT_INVALID_PIN:
                return 'The saved project identity is invalid. Choose a valid project identity before running any action.';
            case self::CONFLICT_PIN_MISMATCH:
                return "The saved project name '$this->projectName' owns no Docker resources, but another candidate does. "
                    . 'Choose the existing runtime identity before running any action.';
            case self::CONFLICT_SHARED_IDENTITY:
                return "Another stack folder also claims project '$this->projectName'. Resolve the duplicate stack metadata before running any action.";
            case self::CONFLICT_DIRECTORY_MISMATCH:
                return 'Matching Docker resources reference a different Compose directory. Confirm ownership before choosing a project identity.';
            case self::CONFLICT_PERSIST_FAILED:
                return 'The project identity could not be saved. Actions are blocked until stack metadata is writable.';
            case self::CONFLICT_PROBE_FAILED:
                return 'Docker could not be queried, so the runtime project name of this imported stack cannot be verified. '
                    . 'Actions are blocked until Docker is reachable.';
        }

        if ($this->source === self::SOURCE_LEGACY_RUNTIME) {
            return "Preserved the imported runtime project name '$this->projectName'.";
        }

        return "Using project name '$this->projectName'.";
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * Read and sanitize the pinned identity, if any.
     */
    private static function readPinned(string $stackPath): ?string
    {
        $file = rtrim($stackPath, '/') . '/' . self::METADATA_FILE;
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        return $raw === false ? '' : trim($raw);
    }

    private function persist(string $stackPath): void
    {
        if (self::hasCompetingClaim($stackPath, $this->projectName)) {
            $this->source = self::SOURCE_UNRESOLVED;
            $this->conflictReason = self::CONFLICT_SHARED_IDENTITY;
            return;
        }
        $this->resolved = self::pin($stackPath, $this->projectName, $this->source, $this->origin);
        if (!$this->resolved) {
            $this->source = self::SOURCE_UNRESOLVED;
            $this->conflictReason = self::CONFLICT_PERSIST_FAILED;
        }
    }

    private static function hasCompetingClaim(string $stackPath, string $projectName): bool
    {
        foreach (glob(dirname($stackPath) . '/*', GLOB_ONLYDIR) ?: [] as $sibling) {
            if (realpath($sibling) === realpath($stackPath)) {
                continue;
            }
            $pinned = self::readPinned($sibling);
            if ($pinned !== null) {
                if ($pinned === $projectName) {
                    return true;
                }
                continue;
            }
            if (!is_file($sibling . '/docker-compose.yml') && !is_file($sibling . '/compose.yaml')
                && !is_file($sibling . '/compose.yml') && !is_file($sibling . '/docker-compose.yaml')
                && !is_file($sibling . '/indirect')) {
                continue;
            }
            $raw = @file_get_contents($sibling . '/name');
            if ($raw === false && self::hasLegacyOrigin()) {
                $raw = @file_get_contents($sibling . '/Name');
            }
            $name = is_string($raw) && trim($raw) !== '' ? trim($raw) : basename($sibling);
            if (in_array($projectName, self::candidateNames(basename($sibling), $name), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Persist the resolved identity so it is never recomputed.
     */
    private static function pin(string $stackPath, string $projectName, string $source, string $origin): bool
    {
        $file = rtrim($stackPath, '/') . '/' . self::METADATA_FILE;
        $previous = self::readPinned($stackPath);
        if ($previous !== null && $previous !== $projectName) {
            $backup = $file . '.pre-migration';
            if (!file_exists($backup) && !self::atomicWrite($backup, (string) file_get_contents($file))) {
                return false;
            }
        }
        $state = [
            'version' => self::MIGRATION_VERSION,
            'projectName' => $projectName,
            'source' => $source,
            'origin' => $origin,
            'legacyRevision' => $origin === 'dcflachs' ? self::LEGACY_REVISION : null,
        ];
        $stateFile = rtrim($stackPath, '/') . '/' . self::STATE_FILE;
        $previousRaw = @file_get_contents($stateFile);
        $previousState = is_string($previousRaw) ? json_decode($previousRaw, true) : null;
        if (is_array($previousState) && ($previousState['projectName'] ?? null) === $projectName
            && isset($previousState['composeFiles'], $previousState['composeFilesSource'])) {
            $state['composeFiles'] = $previousState['composeFiles'];
            $state['composeFilesSource'] = $previousState['composeFilesSource'];
        }
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return is_string($encoded) && self::atomicWrite($file, $projectName)
            && self::atomicWrite($stateFile, $encoded . "\n");
    }

    public function resolveLegacyComposeFiles(string $stackPath, array $discovered): array
    {
        if (!$this->resolved) {
            return $discovered;
        }
        $lock = @fopen(rtrim($stackPath, '/') . '/project_identity.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not lock migrated Compose file metadata.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock migrated Compose file metadata.');
            }
            $file = rtrim($stackPath, '/') . '/' . self::STATE_FILE;
            $raw = @file_get_contents($file);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($state) || ($state['projectName'] ?? null) !== $this->projectName
                || self::readPinned($stackPath) !== $this->projectName) {
                throw new \RuntimeException('Project identity changed while resolving migrated Compose files.');
            }
            if (array_key_exists('composeFiles', $state)) {
                return self::validateComposeFiles($state['composeFiles']);
            }
            $probe = self::probe();
            if ($probe === null) {
                throw new \RuntimeException('Docker could not be queried to verify historical Compose file order.');
            }
            if (!self::matchesResourceDirectory($stackPath, $this->projectName)) {
                throw new \RuntimeException('Runtime Compose directory conflicts with the imported stack.');
            }
            $lists = self::$projectFileLists[$this->projectName] ?? [];
            $lists = array_values(array_unique($lists, SORT_REGULAR));
            if (count($lists) > 1) {
                throw new \RuntimeException('Conflicting runtime Compose file lists. Resolve historical inputs before running any action.');
            }
            if (count($discovered) > 1 && (($lists === [] && self::hasResources(self::countsFor($probe, $this->projectName)))
                || (self::$missingFileEvidence[$this->projectName] ?? false))) {
                throw new \RuntimeException('Historical Compose file order cannot be verified for this imported stack.');
            }
            $files = self::validateComposeFiles($lists[0] ?? $discovered);
            $state['composeFiles'] = $files;
            $state['composeFilesSource'] = $lists === [] ? 'archived-discovery' : 'runtime-labels';
            $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($encoded) || !self::atomicWrite($file, $encoded . "\n")) {
                throw new \RuntimeException('Could not save migrated Compose file order.');
            }
            return $files;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function validateComposeFiles(mixed $files): array
    {
        if (!is_array($files) || !array_is_list($files) || $files === []) {
            throw new \RuntimeException('Migrated Compose file list is invalid.');
        }
        foreach ($files as $file) {
            if (!is_string($file) || !str_starts_with($file, '/') || strpbrk($file, "\r\n") !== false
                || !is_file($file) || !is_readable($file)) {
                throw new \RuntimeException('Migrated Compose input is unavailable. Restore the original file before running any action.');
            }
        }
        return $files;
    }

    public static function initializeNew(string $stackPath, string $projectName): void
    {
        if (!compose_manager_is_valid_project_name($projectName)
            || self::hasCompetingClaim($stackPath, $projectName)
            || !self::pin($stackPath, $projectName, 'new', 'plus')) {
            throw new \RuntimeException('Failed to initialize project identity');
        }
    }

    private static function matchesResourceDirectory(string $stackPath, string $projectName): bool
    {
        $directories = self::$projectDirectories[$projectName] ?? [];
        $expected = [rtrim($stackPath, '/')];
        $indirect = @file_get_contents(rtrim($stackPath, '/') . '/indirect');
        if (is_string($indirect) && trim($indirect) !== '') {
            $target = trim($indirect);
            $expected[] = is_file($target) ? dirname($target) : rtrim($target, '/');
        }
        foreach ($directories as $directory) {
            $matches = false;
            foreach ($expected as $candidate) {
                if (rtrim($directory, '/') === $candidate
                    || (realpath($directory) !== false && realpath($directory) === realpath($candidate))) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                return false;
            }
        }
        return true;
    }

    private function legacyDisplayName(string $stackPath): string
    {
        $raw = @file_get_contents(rtrim($stackPath, '/') . '/name');
        if ($raw === false && $this->origin === 'dcflachs') {
            $raw = @file_get_contents(rtrim($stackPath, '/') . '/Name');
        }
        return is_string($raw) && trim($raw) !== '' ? trim($raw) : $this->projectFolder;
    }

    private static function atomicWrite(string $file, string $content): bool
    {
        $stackPath = dirname($file);
        $temporary = @tempnam($stackPath, '.project-name-');
        if ($temporary === false) {
            return false;
        }
        try {
            return @file_put_contents($temporary, $content) === strlen($content)
                && @chmod($temporary, is_file($file) ? (fileperms($file) & 0777) : 0644)
                && @rename($temporary, $file);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param array<string, array{containers: int, volumes: int, networks: int}> $probe
     * @return array{containers: int, volumes: int, networks: int}
     */
    private static function countsFor(array $probe, string $candidate): array
    {
        return $probe[$candidate] ?? ['containers' => 0, 'volumes' => 0, 'networks' => 0];
    }

    /**
     * @param array{containers: int, volumes: int, networks: int} $counts
     */
    private static function hasResources(array $counts): bool
    {
        return ($counts['containers'] + $counts['volumes'] + $counts['networks']) > 0;
    }

    /**
     * Collect `com.docker.compose.project` ownership across containers, volumes
     * and networks.
     *
     * Volumes and networks matter as much as containers: a stack that was taken
     * down still owns named volumes, and re-creating it under a different
     * project name would silently hand it empty storage.
     *
     * @return array<string, array{containers: int, volumes: int, networks: int}>|null Null when Docker could not be queried
     */
    public static function probe(): ?array
    {
        if (self::$probeOverride !== null) {
            $result = (self::$probeOverride)();
            return is_array($result) ? $result : null;
        }

        if (self::$probeCached) {
            return self::$probeCache;
        }

        self::$probeCached = true;
        self::$probeCache = null;

        $label = 'com.docker.compose.project';
        $containerFormat = 'containers {"project":{{json (.Label "com.docker.compose.project")}},"workingDir":{{json (.Label "com.docker.compose.project.working_dir")}},"configFiles":{{json (.Label "com.docker.compose.project.config_files")}}}';
        $cmd = 'docker ps -a --filter label=' . $label . ' --format ' . escapeshellarg($containerFormat) . ' 2>/dev/null'
            . ' && docker volume ls --filter label=' . $label . ' --format "volumes {{.Labels}}" 2>/dev/null'
            . ' && docker network ls --filter label=' . $label . ' --format "networks {{.Labels}}" 2>/dev/null'
            . '; printf "__rc=%s" "$?"';

        $output = shell_exec($cmd);
        $counts = self::parseProbeOutput(is_string($output) ? $output : null);
        if ($counts === null) {
            composeLogger('Docker project-label probe failed; legacy identity migration deferred', ['output' => $output], 'user', 'warning', 'identity');
            return null;
        }

        self::$probeCache = $counts;
        return $counts;
    }

    /**
     * Parse the tagged `<resource> <labels>` probe output into per-project counts.
     *
     * @return array<string, array{containers: int, volumes: int, networks: int}>|null Null when the probe did not report success
     */
    public static function parseProbeOutput(?string $output): ?array
    {
        if ($output === null || !preg_match('/__rc=(\d+)\s*$/', $output, $rc) || $rc[1] !== '0') {
            return null;
        }

        $counts = [];
        self::$projectDirectories = [];
        self::$projectFileLists = [];
        self::$missingFileEvidence = [];
        foreach (explode("\n", $output) as $line) {
            if (!preg_match('/^(containers|volumes|networks)\s+(.*)$/', trim($line), $m)) {
                continue;
            }
            $details = json_decode($m[2], true);
            if (is_array($details) && isset($details['project']) && is_string($details['project'])) {
                $project = $details['project'];
                if (isset($details['workingDir']) && is_string($details['workingDir']) && $details['workingDir'] !== '') {
                    self::$projectDirectories[$project][] = $details['workingDir'];
                }
                if (isset($details['configFiles']) && is_string($details['configFiles']) && $details['configFiles'] !== '') {
                    self::$projectFileLists[$project][] = explode(',', $details['configFiles']);
                } elseif ($m[1] === 'containers') {
                    self::$missingFileEvidence[$project] = true;
                }
            } else {
                if (!preg_match('/(?:^|,)com\.docker\.compose\.project=([^,]+)/', $m[2], $projectLabel)) {
                    continue;
                }
                $project = trim($projectLabel[1]);
                if ($m[1] === 'containers') {
                    self::$missingFileEvidence[$project] = true;
                }
            }
            if ($project === '') {
                continue;
            }
            if (!isset($counts[$project])) {
                $counts[$project] = ['containers' => 0, 'volumes' => 0, 'networks' => 0];
            }
            $counts[$project][$m[1]]++;
        }

        return $counts;
    }
}
