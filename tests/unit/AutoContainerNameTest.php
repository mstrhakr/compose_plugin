<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/ComposeCommandBuilder.php';
require_once '/usr/local/emhttp/plugins/compose.manager/include/Helpers.php';

/**
 * Automatic container names: with the opt-in `auto_container_name` file and
 * Override File Management on Automatic, `up` writes `container_name` into the
 * plugin-managed override for services that have none.
 *
 * A stand-in docker on PATH answers `compose ... config --format json` with
 * the services of the test, as compose prints them without the managed
 * override. It fails if it is handed the managed override, because the names
 * must be worked out from the compose files alone.
 */
final class AutoContainerNameTest extends TestCase
{
    private string $root;
    private string $bin;
    private string $originalPath;

    protected function setUp(): void
    {
        parent::setUp();
        \StackInfo::clearCache();
        $this->root = $this->createTempDir();
        $this->bin = $this->createTempDir();
        $this->originalPath = (string) getenv('PATH');

        file_put_contents($this->bin . '/docker', <<<'SH'
#!/bin/bash
dir="$(dirname "$0")"
printf '%s\n' "$*" >> "$dir/calls.log"
for arg in "$@"; do
  case "$arg" in
    *compose.override.yaml) echo 'handed the managed override' >&2; exit 1 ;;
  esac
done
[ -f "$dir/fail" ] && { echo 'compose failed' >&2; exit 1; }
case " $* " in
  *" --services "*) cat "$dir/services.txt" ;;
  *) cat "$dir/config.json" ;;
esac
SH);
        chmod($this->bin . '/docker', 0755);
        putenv('PATH=' . $this->bin . ':' . $this->originalPath);
    }

    protected function tearDown(): void
    {
        putenv('PATH=' . $this->originalPath);
        \StackInfo::clearCache();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $services What `docker compose config` reports without the managed override
     */
    private function makeStack(string $name, array $services, bool $optIn = true, ?string $displayName = null): string
    {
        $stackDir = $this->root . '/' . $name;
        mkdir($stackDir);
        file_put_contents($stackDir . '/name', $displayName ?? $name);
        file_put_contents($stackDir . '/compose.yaml', "services:\n  web:\n    image: nginx\n");
        if ($optIn) {
            file_put_contents($stackDir . '/auto_container_name', 'true');
        }
        file_put_contents($this->bin . '/config.json', json_encode(['services' => $services]));
        file_put_contents($this->bin . '/services.txt', implode("\n", array_keys($services)) . "\n");
        return $stackDir;
    }

    // --- names -------------------------------------------------------------

    public function testServiceNamedLikeTheStackGetsTheStackNameAndOthersAreSuffixed(): void
    {
        $names = \StackInfo::buildAutoContainerNames('app', ['app' => [], 'db' => [], 'cache' => []]);
        $this->assertSame(['app' => 'app', 'db' => 'app-db', 'cache' => 'app-cache'], $names);
    }

    public function testNameFromTheComposeFileIsLeftToTheComposeFile(): void
    {
        $services = ['db' => ['container_name' => 'mine-db'], 'cache' => []];
        $this->assertSame(['cache' => 'app-cache'], \StackInfo::buildAutoContainerNames('app', $services));
        $this->assertSame(['db'], \StackInfo::containerNamesToDrop($services));
    }

    public function testServicesWithMoreThanOneReplicaGetNoName(): void
    {
        $services = [
            'a' => ['deploy' => ['replicas' => 2]],
            'b' => ['scale' => 3],
            'c' => ['deploy' => ['replicas' => 1]],
        ];
        $this->assertSame(['c' => 'app-c'], \StackInfo::buildAutoContainerNames('app', $services));
        $this->assertSame(['a', 'b'], \StackInfo::containerNamesToDrop($services));
    }

    public function testServiceNameThatIsNotAValidContainerNameIsSkipped(): void
    {
        $names = \StackInfo::buildAutoContainerNames('app', ['ok' => [], 'bad name' => [], 'bad/slash' => []]);
        $this->assertSame(['ok' => 'app-ok'], $names);
    }

    // --- editing the override text ------------------------------------------

    public function testTemplateOverrideIsOpenedUpAndGetsTheNames(): void
    {
        $template = "# Override file for UI labels\nservices: {}\n";
        $edit = applyOverrideContainerNames($template, ['web' => 'app-web'], []);

        $this->assertTrue($edit['changed']);
        $this->assertSame("# Override file for UI labels\nservices:\n  \"web\":\n    container_name: \"app-web\"\n", $edit['content']);
        $this->assertSame(['web'], $edit['set']);
    }

    public function testNameGoesUnderAnExistingServiceAtItsIndentation(): void
    {
        $content = "services:\n  \"web\":\n    \"labels\":\n      \"net.unraid.docker.icon\": \"x.png\"\n  \"db\":\n    \"labels\": {}\n";
        $edit = applyOverrideContainerNames($content, ['web' => 'app-web', 'db' => 'app-db'], []);

        $this->assertSame(
            "services:\n  \"web\":\n    container_name: \"app-web\"\n    \"labels\":\n      \"net.unraid.docker.icon\": \"x.png\"\n"
            . "  \"db\":\n    container_name: \"app-db\"\n    \"labels\": {}\n",
            $edit['content']
        );
    }

    public function testNameAlreadyInTheOverrideIsNeverReplaced(): void
    {
        $content = "services:\n  web:\n    container_name: \"typed-by-user\"\n";
        $edit = applyOverrideContainerNames($content, ['web' => 'app-web'], []);

        $this->assertFalse($edit['changed']);
        $this->assertSame($content, $edit['content']);
    }

    public function testNameNestedDeeperIsNotTakenForTheServiceName(): void
    {
        // A label value or a nested key called container_name is not the service's own.
        $content = "services:\n  web:\n    labels:\n      container_name: \"label\"\n";
        $edit = applyOverrideContainerNames($content, ['web' => 'app-web'], []);

        $this->assertSame("services:\n  web:\n    container_name: \"app-web\"\n    labels:\n      container_name: \"label\"\n", $edit['content']);
    }

    public function testNameIsRemovedWhenTheComposeFileSetsOne(): void
    {
        $content = "services:\n  web:\n    container_name: \"app-web\"\n    labels:\n      a: \"b\"\n";
        $edit = applyOverrideContainerNames($content, [], ['web']);

        $this->assertSame("services:\n  web:\n    labels:\n      a: \"b\"\n", $edit['content']);
        $this->assertSame(['web'], $edit['removed']);
    }

    public function testNewServiceIsAddedInsideServicesBeforeTheNextTopLevelKey(): void
    {
        $content = "services:\n  web:\n    labels: {}\n\nx-notes:\n  a: 1\n";
        $edit = applyOverrideContainerNames($content, ['db' => 'app-db'], []);

        $this->assertSame("services:\n  web:\n    labels: {}\n  \"db\":\n    container_name: \"app-db\"\n\nx-notes:\n  a: 1\n", $edit['content']);
    }

    public function testFlowStyleServiceIsLeftAloneAndReported(): void
    {
        $content = "services:\n  web: {labels: {a: b}}\n";
        $edit = applyOverrideContainerNames($content, ['web' => 'app-web'], []);

        $this->assertFalse($edit['changed']);
        $this->assertSame(['web'], $edit['skipped']);
    }

    public function testHandEditedIndentationIsLeftAlone(): void
    {
        $content = "services:\n    web:\n        labels: {}\n";
        $edit = applyOverrideContainerNames($content, ['web' => 'app-web', 'db' => 'app-db'], []);

        $this->assertFalse($edit['changed']);
        $this->assertSame($content, $edit['content']);
        $this->assertSame(['web', 'db'], $edit['skipped']);
    }

    public function testEmptyOrMissingFileGetsAServicesBlock(): void
    {
        $edit = applyOverrideContainerNames('', ['web' => 'app-web'], []);
        $this->assertSame("services:\n  \"web\":\n    container_name: \"app-web\"\n", $edit['content']);
    }

    public function testWindowsLineEndingsAreKept(): void
    {
        $edit = applyOverrideContainerNames("services:\r\n  web:\r\n    labels: {}\r\n", ['web' => 'app-web'], []);
        $this->assertSame("services:\r\n  web:\r\n    container_name: \"app-web\"\r\n    labels: {}\r\n", $edit['content']);
    }

    public function testNothingToDoLeavesTheContentAsItIs(): void
    {
        $edit = applyOverrideContainerNames("services: {}\n", [], ['web']);
        $this->assertFalse($edit['changed']);
        $this->assertSame("services: {}\n", $edit['content']);
    }

    // --- at Compose Up -------------------------------------------------------

    public function testUpWritesTheNamesIntoTheManagedOverrideAndPassesIt(): void
    {
        $stackDir = $this->makeStack('app', ['app' => [], 'db' => [], 'cache' => ['container_name' => 'mine-cache']]);

        $spec = \ComposeCommandBuilder::fromProject($this->root, 'app', 'up');

        $override = $stackDir . '/compose.override.yaml';
        $this->assertSame([$stackDir . '/compose.yaml', $override], $spec['composeFiles']);
        $content = (string) file_get_contents($override);
        $this->assertStringContainsString("\"app\":\n    container_name: \"app\"", $content);
        $this->assertStringContainsString("\"db\":\n    container_name: \"app-db\"", $content);
        $this->assertStringNotContainsString('cache', $content);
    }

    public function testComposeFileNameRemovesTheOneInTheOverride(): void
    {
        $stackDir = $this->makeStack('app', ['web' => ['container_name' => 'from-compose']]);
        file_put_contents($stackDir . '/compose.override.yaml', "services:\n  web:\n    container_name: \"app-web\"\n");

        \ComposeCommandBuilder::fromProject($this->root, 'app', 'up');

        $this->assertSame("services:\n  web: {}\n", (string) file_get_contents($stackDir . '/compose.override.yaml'));
    }

    public function testStoredNameSurvivesARenamedStack(): void
    {
        // Written while the stack was called "old"; the stack is now "app".
        $stackDir = $this->makeStack('app', ['web' => []]);
        $stored = "services:\n  web:\n    container_name: \"old-web\"\n";
        file_put_contents($stackDir . '/compose.override.yaml', $stored);

        \ComposeCommandBuilder::fromProject($this->root, 'app', 'up');

        $this->assertSame($stored, (string) file_get_contents($stackDir . '/compose.override.yaml'));
    }

    public function testNamesUseTheProjectNameNotTheDisplayName(): void
    {
        // 'My Media' sanitizes to the project name 'my_media' (what `-p` gets).
        $stackDir = $this->makeStack('my_media', ['my_media' => [], 'db' => []], true, 'My Media');

        $spec = \ComposeCommandBuilder::fromProject($this->root, 'my_media', 'up');

        $this->assertSame('my_media', $spec['projectName']);
        $content = (string) file_get_contents($stackDir . '/compose.override.yaml');
        $this->assertStringContainsString("\"my_media\":\n    container_name: \"my_media\"", $content);
        $this->assertStringContainsString("\"db\":\n    container_name: \"my_media-db\"", $content);
        $this->assertStringNotContainsString('My Media', $content);
    }

    public function testDefaultComposeFileDiscoveryGetsNamesToo(): void
    {
        // Compose finds compose.override.yaml next to compose.yaml by itself.
        $stackDir = $this->makeStack('disc', ['web' => []]);
        file_put_contents($stackDir . '/use_default_compose_files', 'true');

        $spec = \ComposeCommandBuilder::fromProject($this->root, 'disc', 'up');

        $this->assertSame([], $spec['composeFiles']);
        $this->assertStringContainsString('container_name: "disc-web"', (string) file_get_contents($stackDir . '/compose.override.yaml'));
    }

    public function testNothingIsWrittenWithoutOptIn(): void
    {
        $stackDir = $this->makeStack('plain', ['web' => []], false);

        $spec = \ComposeCommandBuilder::fromProject($this->root, 'plain', 'up');

        $this->assertSame([$stackDir . '/compose.yaml'], $spec['composeFiles']);
        $this->assertFileDoesNotExist($stackDir . '/compose.override.yaml');
    }

    public function testNothingIsWrittenWhenOverrideManagementIsManual(): void
    {
        $stackDir = $this->makeStack('manual', ['web' => []]);
        file_put_contents($stackDir . '/labels_view_mode', 'advanced');

        \ComposeCommandBuilder::fromProject($this->root, 'manual', 'up');

        $this->assertFileDoesNotExist($stackDir . '/compose.override.yaml');
        $stack = \StackInfo::fromProject($this->root, 'manual');
        $this->assertStringContainsString('Manual', (string) $stack->pluginManagedOverrideBlocker());
        $this->assertNull($stack->pluginManagedOverrideBlocker(true));
    }

    public function testOnlyUpAndUpdateWriteNames(): void
    {
        $stackDir = $this->makeStack('app', ['web' => []]);

        \ComposeCommandBuilder::fromProject($this->root, 'app', 'down');
        \ComposeCommandBuilder::fromProject($this->root, 'app', 'pull');

        $this->assertFileDoesNotExist($stackDir . '/compose.override.yaml');
    }

    public function testComposeUpFromTheStackMenuWritesTheNamesFirst(): void
    {
        global $compose_root, $plugin_root, $sName, $socket_name;
        $compose_root = $this->root;
        $plugin_root = '/usr/local/emhttp/plugins/compose.manager';
        $sName = 'compose.manager';
        $socket_name = 'compose_test';
        \PluginTests\Mocks\FunctionMocks::setPluginConfig('compose.manager', ['DEBUG_TO_LOG' => 'false']);
        $varFile = $this->createTempDir() . '/var.ini';
        file_put_contents($varFile, "mdState=STARTED\nfsState=Started\n");
        \PluginTests\StreamWrapper\UnraidStreamWrapper::addMapping('/var/local/emhttp/var.ini', $varFile);
        $stackDir = $this->makeStack('menu', ['web' => [], 'db' => []]);
        $_POST['path'] = $stackDir;
        $_POST['profile'] = '';

        try {
            ob_start();
            echoComposeCommand('up');
            $output = (string) ob_get_clean();
        } finally {
            unset($_POST['path'], $_POST['profile']);
        }

        $this->assertStringContainsString('ShowTtyd.php', $output);
        $content = (string) file_get_contents($stackDir . '/compose.override.yaml');
        $this->assertStringContainsString('container_name: "menu-web"', $content);
        $this->assertStringContainsString('container_name: "menu-db"', $content);
    }

    public function testComposeFailureLeavesTheOverrideAloneAndUpStillRuns(): void
    {
        $stackDir = $this->makeStack('app', ['web' => []]);
        touch($this->bin . '/fail');

        $spec = \ComposeCommandBuilder::fromProject($this->root, 'app', 'up');

        $this->assertSame([$stackDir . '/compose.yaml'], $spec['composeFiles']);
        $this->assertFileDoesNotExist($stackDir . '/compose.override.yaml');
        $this->assertSame(['changed' => false, 'set' => [], 'removed' => [], 'skipped' => []],
            array_diff_key(\StackInfo::fromProject($this->root, 'app')->syncAutoContainerNames(), ['error' => true]));
    }
}
