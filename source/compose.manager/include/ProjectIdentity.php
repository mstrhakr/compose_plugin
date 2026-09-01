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
 *  - When the two candidates agree there is nothing to migrate.
 *  - When they differ, live Docker resources labelled
 *    `com.docker.compose.project` decide the winner.
 *  - When exactly one candidate owns resources it is preserved and pinned.
 *  - When neither owns anything there is nothing to strand, so the canonical
 *    folder-derived name is pinned.
 *  - When both own resources, or Docker cannot be probed, the identity is left
 *    UNRESOLVED and every mutating action must refuse to run (fail closed).
 *
 * The winning value is persisted to the per-stack `project_name` file so the
 * decision is made once and every later code path reads the same identity.
 */
final class ProjectIdentity
{
    /** Per-stack metadata file holding the pinned runtime project name. */
    public const METADATA_FILE = 'project_name';

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

    /** @var string Stack directory basename */
    public string $projectFolder;
    /** @var string Sanitized folder-derived candidate (the canonical Plus identity) */
    public string $folderCandidate;
    /** @var string Sanitized `name`-file candidate (the original plugin's identity) */
    public string $legacyCandidate;
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
        $identity = new self();
        $identity->projectFolder = $projectFolder;
        $identity->folderCandidate = compose_manager_sanitize_project_name($projectFolder);

        $rawLegacy = ($displayName === null) ? '' : trim($displayName);
        $identity->legacyCandidate = ($rawLegacy === '')
            ? $identity->folderCandidate
            : compose_manager_sanitize_project_name($rawLegacy);

        $pinned = self::readPinned($stackPath);
        if ($pinned !== null) {
            $identity->projectName = $pinned;
            $identity->source = self::SOURCE_PERSISTED;
            $identity->resolved = true;
            return $identity;
        }

        // Candidates agree: the Plus identity is already the legacy identity.
        if ($identity->legacyCandidate === $identity->folderCandidate) {
            $identity->projectName = $identity->folderCandidate;
            $identity->source = self::SOURCE_CANONICAL;
            $identity->resolved = true;
            self::pin($stackPath, $identity->projectName);
            return $identity;
        }

        $probe = self::probe();
        if ($probe === null) {
            // Docker is unreachable: "no containers found" would be a lie, so
            // refuse to pin anything and block mutations until we can prove it.
            $identity->projectName = $identity->folderCandidate;
            $identity->source = self::SOURCE_UNRESOLVED;
            $identity->conflictReason = self::CONFLICT_PROBE_FAILED;
            $identity->resolved = false;
            return $identity;
        }

        $identity->evidence = [
            $identity->folderCandidate => self::countsFor($probe, $identity->folderCandidate),
            $identity->legacyCandidate => self::countsFor($probe, $identity->legacyCandidate),
        ];

        $folderOwns = self::hasResources($identity->evidence[$identity->folderCandidate]);
        $legacyOwns = self::hasResources($identity->evidence[$identity->legacyCandidate]);

        if ($folderOwns && $legacyOwns) {
            $identity->projectName = $identity->folderCandidate;
            $identity->source = self::SOURCE_UNRESOLVED;
            $identity->conflictReason = self::CONFLICT_AMBIGUOUS;
            $identity->resolved = false;
            composeLogger(
                "Ambiguous compose project identity for '$projectFolder'; mutating actions are blocked until an identity is chosen",
                ['folderCandidate' => $identity->folderCandidate, 'legacyCandidate' => $identity->legacyCandidate, 'evidence' => $identity->evidence],
                'user',
                'warning',
                'identity'
            );
            return $identity;
        }

        if ($legacyOwns) {
            $identity->projectName = $identity->legacyCandidate;
            $identity->source = self::SOURCE_LEGACY_RUNTIME;
        } elseif ($folderOwns) {
            $identity->projectName = $identity->folderCandidate;
            $identity->source = self::SOURCE_FOLDER_RUNTIME;
        } else {
            $identity->projectName = $identity->folderCandidate;
            $identity->source = self::SOURCE_UNUSED;
        }

        $identity->resolved = true;
        self::pin($stackPath, $identity->projectName);
        composeLogger(
            "Pinned compose project identity '{$identity->projectName}' for '$projectFolder' ({$identity->source})",
            ['folderCandidate' => $identity->folderCandidate, 'legacyCandidate' => $identity->legacyCandidate, 'evidence' => $identity->evidence],
            'user',
            'info',
            'identity'
        );

        return $identity;
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
        $choice = compose_manager_sanitize_project_name(trim($choice));
        if ($choice !== $this->folderCandidate && $choice !== $this->legacyCandidate) {
            throw new \RuntimeException("Project identity '$choice' is not a candidate for '$this->projectFolder'");
        }
        if (!self::pin($stackPath, $choice)) {
            throw new \RuntimeException("Failed to persist project identity for '$this->projectFolder'");
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
                return "Both '$this->folderCandidate' and '$this->legacyCandidate' own live Docker resources. "
                    . 'Choose which one this stack should use before running any action.';
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
        if ($raw === false || trim($raw) === '') {
            return null;
        }
        return compose_manager_sanitize_project_name(trim($raw));
    }

    /**
     * Persist the resolved identity so it is never recomputed.
     */
    private static function pin(string $stackPath, string $projectName): bool
    {
        $file = rtrim($stackPath, '/') . '/' . self::METADATA_FILE;
        return @file_put_contents($file, $projectName) !== false;
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
        $cmd = 'docker ps -a --filter label=' . $label . ' --format "containers {{.Labels}}" 2>/dev/null'
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
        foreach (explode("\n", $output) as $line) {
            if (!preg_match('/^(containers|volumes|networks)\s+(.*)$/', trim($line), $m)) {
                continue;
            }
            if (!preg_match('/com\.docker\.compose\.project=([^,]+)/', $m[2], $p)) {
                continue;
            }
            $project = trim($p[1]);
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
