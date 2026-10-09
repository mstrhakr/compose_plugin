<?php

declare(strict_types=1);

namespace ComposeManager\Tests;

use ComposeManager\Tests\Support\FakeDocker;
use GitDeployCheck;
use PluginTests\TestCase;

require_once '/usr/local/emhttp/plugins/compose.manager/include/GitDeployCheck.php';
require_once __DIR__ . '/support/FakeDocker.php';

final class GitDeployCheckTest extends TestCase
{
    private FakeDocker $docker;
    private string $mnt;
    private string $clone;
    private string $stackDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docker = new FakeDocker();
        $this->mnt = COMPOSE_GIT_MNT_DIR;
        FakeDocker::removeTree($this->mnt);
        mkdir($this->mnt . '/user/appdata/existing', 0755, true);
        file_put_contents(COMPOSE_UNRAID_VAR_INI, "mdState=\"STARTED\"\n");
        file_put_contents(COMPOSE_MOUNTS_FILE, "rootfs {$this->mnt} rootfs rw 0 0\nshfs {$this->mnt}/user fuse.shfs rw 0 0\n");

        $this->clone = $this->mnt . '/user/appdata/git/whoami-0123abcd';
        mkdir($this->clone . '/whoami', 0755, true);
        $this->stackDir = sys_get_temp_dir() . '/compose_git_check_stack';
        FakeDocker::removeTree($this->stackDir);
        mkdir($this->stackDir);
    }

    protected function tearDown(): void
    {
        $this->docker->cleanUp();
        FakeDocker::removeTree($this->mnt);
        FakeDocker::removeTree($this->stackDir);
        parent::tearDown();
    }

    public function testCleanStackHasNoProblems(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox']));
        $this->assertSame([], $this->check());
    }

    public function testComposeFileWithNoServicesIsAProblem(): void
    {
        $this->docker->setConfig(['name' => 'whoami', 'services' => []]);
        $this->assertStringContainsString('defines no services', $this->check()[0]);
    }

    // ----- compose itself -----

    public function testComposeErrorIsReportedWithComposesMessage(): void
    {
        $this->docker->setConfig([], "yaml: line 2: found character that cannot start any token\n", 1);
        $problems = $this->check();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('found character that cannot start any token', $problems[0]);
    }

    public function testUnsetVariablesAreProblems(): void
    {
        $stderr = 'time="2026-10-02T21:10:16-04:00" level=warning msg="The \\"DB_PASS\\" variable is not set. Defaulting to a blank string."' . "\n"
            . 'time="2026-10-02T21:10:16-04:00" level=warning msg="The \\"TZ\\" variable is not set. Defaulting to a blank string."' . "\n";
        $this->docker->setConfig($this->config(['image' => 'busybox']), $stderr);

        $problems = $this->check();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('DB_PASS, TZ', $problems[0]);
    }

    public function testComposeIsRunWithTheStacksArgumentsAndProjectName(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox']));
        $this->check();
        $this->assertContains('compose -f ' . $this->clone . '/whoami/compose.yaml -p whoami config --format json', $this->docker->calls());
    }

    // ----- .env.example -----

    public function testEnvExampleKeysMissingFromEnvAreProblems(): void
    {
        file_put_contents($this->clone . '/whoami/.env.example', "TZ=\nDB_PASS=changeme\n");
        file_put_contents($this->stackDir . '/.env', "TZ=Europe/London\n");
        $this->docker->setConfig($this->config(['image' => 'busybox']));

        $problems = $this->check();

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('DB_PASS', $problems[0]);
        $this->assertStringNotContainsString('changeme', $problems[0]);
    }

    public function testMissingEnvFileMeansEveryExampleKeyIsMissing(): void
    {
        file_put_contents($this->clone . '/whoami/.env.example', "A=\nB=\n");
        $this->docker->setConfig($this->config(['image' => 'busybox']));
        $this->assertStringContainsString('A, B', $this->check()[0]);
    }

    public function testEnvKeysAreReadFromEveryUsualForm(): void
    {
        $content = "\xEF\xBB\xBF# comment\r\n\r\nexport A=1\r\nB = 2\r\nC=\"x=y\"\r\nD\r\n  E=5\r\nnot a key\r\n1BAD=x\r\nA=again\r\n";
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], GitDeployCheck::readEnvKeys($content));
    }

    // ----- external networks and volumes -----

    public function testMissingExternalNetworkAndVolumeAreProblems(): void
    {
        $config = $this->config(['image' => 'busybox']);
        $config['networks'] = ['proxy' => ['name' => 'zz-proxy', 'external' => true], 'default' => ['name' => 'whoami_default']];
        $config['volumes'] = ['ext' => ['name' => 'zz-ext', 'external' => true], 'local' => ['name' => 'whoami_local']];
        $this->docker->setConfig($config);

        $problems = $this->check();
        $this->assertCount(2, $problems);
        $this->assertStringContainsString("The external network 'zz-proxy' does not exist", $problems[0]);
        $this->assertStringContainsString("The external volume 'zz-ext' does not exist", $problems[1]);

        $this->docker->addNetwork('zz-proxy');
        $this->docker->addVolume('zz-ext');
        $this->assertSame([], $this->check());
    }

    public function testExternalNetworkAndVolumeThatDockerCannotBeAskedAboutAreNotCalledMissing(): void
    {
        $config = $this->config(['image' => 'busybox']);
        $config['networks'] = ['proxy' => ['name' => 'zz-proxy', 'external' => true]];
        $config['volumes'] = ['ext' => ['name' => 'zz-ext', 'external' => true]];
        $this->docker->setConfig($config);
        $daemonDown = 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?';
        $this->docker->fail('network', $daemonDown);
        $this->docker->fail('volume', $daemonDown);

        $problems = $this->check();
        $this->assertCount(2, $problems);
        $this->assertStringContainsString("Could not check whether the external network 'zz-proxy' exists: Cannot connect", $problems[0]);
        $this->assertStringContainsString("Could not check whether the external volume 'zz-ext' exists: Cannot connect", $problems[1]);
    }

    public function testOlderDockersWordingForAMissingNetworkOrVolumeCountsAsMissing(): void
    {
        $config = $this->config(['image' => 'busybox']);
        $config['networks'] = ['proxy' => ['name' => 'zz-proxy', 'external' => true]];
        $config['volumes'] = ['ext' => ['name' => 'zz-ext', 'external' => true]];
        $this->docker->setConfig($config);
        $this->docker->fail('network', 'Error: No such network: zz-proxy');
        $this->docker->fail('volume', 'Error: No such volume: zz-ext');

        $problems = $this->check();
        $this->assertCount(2, $problems);
        $this->assertStringContainsString("The external network 'zz-proxy' does not exist", $problems[0]);
        $this->assertStringContainsString("The external volume 'zz-ext' does not exist", $problems[1]);
    }

    public function testMissingExternalNetworkIsNotAProblemWhenItWillBeCreated(): void
    {
        $config = $this->config(['image' => 'busybox']);
        $config['networks'] = ['proxy' => ['name' => 'zz-proxy', 'external' => true]];
        $config['volumes'] = ['ext' => ['name' => 'zz-ext', 'external' => true]];
        $this->docker->setConfig($config);

        // The volume is still checked: only networks are created by the setting.
        $problems = $this->check(missingNetworksWillBeCreated: true);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString("volume 'zz-ext'", $problems[0]);
    }

    // ----- paths -----

    public function testMisspelledShareInBindSourceIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => $this->mnt . '/user/apdata/whoami', 'target' => '/config'],
        ]]));
        $problems = $this->check();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('neither does ' . $this->mnt . '/user/apdata', $problems[0]);
    }

    public function testNewShareInBindSourceIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => $this->mnt . '/user/newshare', 'target' => '/config'],
        ]]));
        $this->assertCount(1, $this->check());
    }

    public function testMissingFoldersBelowAnExistingShareAreLeftForDocker(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => $this->mnt . '/user/appdata/mystack/thisapp', 'target' => '/config'],
        ]]));
        $check = new GitDeployCheck(
            'whoami',
            ['-f', $this->clone . '/whoami/compose.yaml'],
            $this->clone,
            $this->clone . '/whoami',
            $this->stackDir . '/.env'
        );

        $this->assertSame([], $check->run());
        $this->assertSame([$this->mnt . '/user/appdata/mystack/thisapp'], $check->foldersDockerWillCreate());
    }

    public function testBindSourceOutsideMntWithAMissingTopFolderIsAProblem(): void
    {
        // Such as /mtn/user/appdata, a typo of /mnt.
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => '/compose-git-test-missing/user/appdata', 'target' => '/config'],
        ]]));
        $this->assertStringContainsString('neither does /compose-git-test-missing', $this->check()[0]);
    }

    public function testMissingFolderBelowAnExistingTopFolderOutsideMntIsLeftForDocker(): void
    {
        // /tmp/transcode is emptied at every reboot.
        $source = sys_get_temp_dir() . '/compose-git-test-transcode';
        FakeDocker::removeTree($source);
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => $source, 'target' => '/transcode'],
        ]]));
        $check = new GitDeployCheck('whoami', ['-f', $this->clone . '/whoami/compose.yaml'], $this->clone, $this->clone . '/whoami', null);

        $this->assertSame([], $check->run());
        $this->assertSame([$source], $check->foldersDockerWillCreate());
    }

    public function testMissingFoldersBelowAShareThatIsAZfsDatasetAreLeftForDocker(): void
    {
        // On ZFS the share is its own mount; the share must exist, not the folder below it.
        mkdir($this->mnt . '/cache/appdata', 0755, true);
        file_put_contents(
            COMPOSE_MOUNTS_FILE,
            "rootfs {$this->mnt} rootfs rw 0 0\nshfs {$this->mnt}/user fuse.shfs rw 0 0\n"
            . "cache {$this->mnt}/cache zfs rw 0 0\ncache/appdata {$this->mnt}/cache/appdata zfs rw 0 0\n"
        );
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => $this->mnt . '/cache/appdata/newapp/config', 'target' => '/config'],
        ]]));
        $this->assertSame([], $this->check());
    }

    public function testBindSourceOnAMissingMountIsCalledOutAsRam(): void
    {
        mkdir($this->mnt . '/disk9/appdata', 0755, true);
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => $this->mnt . '/disk9/appdata', 'target' => '/config'],
        ]]));
        $this->assertStringContainsString('RAM', $this->check()[0]);
    }

    public function testBindSourceOnARamMountInsideAPoolIsTheUsersChoice(): void
    {
        // A tmpfs in appdata, such as a RAM transcode folder, is meant to be in RAM. A missing
        // folder in it (empty after every reboot) is left for Docker to create.
        mkdir($this->mnt . '/cache/appdata/ram/transcode', 0755, true);
        file_put_contents(
            COMPOSE_MOUNTS_FILE,
            "rootfs {$this->mnt} rootfs rw 0 0\nshfs {$this->mnt}/user fuse.shfs rw 0 0\n"
            . "/dev/nvme0n1p1 {$this->mnt}/cache xfs rw 0 0\ntmpfs {$this->mnt}/cache/appdata/ram tmpfs rw 0 0\n"
        );
        $missing = $this->mnt . '/cache/appdata/ram/missing';
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => $this->mnt . '/cache/appdata/ram/transcode', 'target' => '/transcode'],
            ['type' => 'bind', 'source' => $missing, 'target' => '/cache'],
        ]]));
        $check = new GitDeployCheck('whoami', ['-f', $this->clone . '/whoami/compose.yaml'], $this->clone, $this->clone . '/whoami', null);

        $this->assertSame([], $check->run());
        $this->assertSame([$missing], $check->foldersDockerWillCreate());
    }

    public function testExistingBindSourceAndMissingSourceInsideTheCloneAreFine(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'volumes' => [
            ['type' => 'bind', 'source' => $this->mnt . '/user/appdata/existing', 'target' => '/config'],
            ['type' => 'bind', 'source' => $this->clone . '/whoami/data', 'target' => '/data'],
            ['type' => 'volume', 'source' => 'named', 'target' => '/named'],
        ]]));
        $this->assertSame([], $this->check());
    }

    public function testMissingConfigSecretAndBuildFolderAreProblems(): void
    {
        $config = $this->config(['build' => ['context' => $this->clone . '/whoami/build', 'dockerfile' => 'Dockerfile']]);
        $config['configs'] = ['app' => ['file' => $this->clone . '/whoami/app.cfg']];
        $config['secrets'] = ['token' => ['file' => $this->clone . '/whoami/token.txt']];
        $this->docker->setConfig($config);

        $this->assertCount(3, $this->check());
    }

    // ----- names and ports -----

    public function testContainerNameTakenByAnotherStackIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'container_name' => 'whoami']));
        $this->docker->addNamedContainer('whoami', 'otherstack');
        $this->assertStringContainsString("belongs to 'otherstack'", $this->check()[0]);
    }

    public function testContainerNameHeldByThisStackOrUnusedIsFine(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'container_name' => 'whoami']));
        $this->assertSame([], $this->check());
        $this->docker->addNamedContainer('whoami', 'whoami');
        $this->assertSame([], $this->check());
    }

    public function testContainerIdThatOnlyStartsWithTheNameIsNotAClash(): void
    {
        // docker inspect "cafe" also finds a container whose id starts with cafe.
        $this->docker->setConfig($this->config(['image' => 'busybox', 'container_name' => 'cafe']));
        file_put_contents(
            $this->docker->dir . '/containers/cafe.json',
            json_encode([['Name' => '/unrelated', 'Config' => ['Labels' => ['com.docker.compose.project' => 'otherstack']]]])
        );

        $this->assertSame([], $this->check());
    }

    public function testContainerNameTakenByAPlainDockerContainerIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'container_name' => 'plex']));
        $this->docker->addNamedContainer('plex', null);
        $this->assertCount(1, $this->check());
    }

    public function testContainerNameCheckThatDockerCannotAnswerIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'container_name' => 'plex']));
        $this->docker->fail('container', 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?');

        $problems = $this->check();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString("Could not check whether the container name 'plex' is free: Cannot connect", $problems[0]);
    }

    public function testPortCheckThatCannotListTheRunningContainersIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'ports' => [
            ['target' => 80, 'published' => '8080', 'protocol' => 'tcp'],
        ]]));
        $this->docker->fail('ps', 'Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?');

        $problems = $this->check();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Could not check whether the published ports are free: docker ps failed: Cannot connect', $problems[0]);
    }

    public function testPortCheckThatCannotInspectTheRunningContainersIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'ports' => [
            ['target' => 80, 'published' => '8080', 'protocol' => 'tcp'],
        ]]));
        $this->docker->setRunning([
            ['name' => 'nginx', 'project' => null, 'ports' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8080']]]],
        ]);
        $this->docker->fail('container', 'error during connect: Get "http://...": context deadline exceeded');

        $problems = $this->check();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('docker inspect failed: error during connect', $problems[0]);
    }

    public function testContainerRemovedBetweenPsAndInspectHoldsNoPort(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'ports' => [
            ['target' => 80, 'published' => '8080', 'protocol' => 'tcp'],
        ]]));
        // docker inspect still prints the containers it found, and exits 1 for the one that is gone.
        $this->docker->fail('container', 'Error response from daemon: No such container: id1', json_encode([
            ['Name' => '/nginx', 'Config' => ['Labels' => []], 'NetworkSettings' => ['Ports' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8080']]]]],
        ]));
        $this->docker->setRunning([
            ['name' => 'nginx', 'project' => null, 'ports' => []],
            ['name' => 'gone', 'project' => null, 'ports' => []],
        ]);

        $problems = $this->check();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString("container 'nginx'", $problems[0]);
    }

    public function testPortPublishedByAnotherContainerIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'ports' => [
            ['target' => 80, 'published' => '8080', 'protocol' => 'tcp'],
        ]]));
        $this->docker->setRunning([
            ['name' => 'nginx', 'project' => null, 'ports' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8080']]]],
        ]);

        $problems = $this->check();
        $this->assertCount(1, $problems);
        $this->assertStringContainsString("container 'nginx'", $problems[0]);
    }

    public function testPortHeldByThisStacksOwnContainerIsFine(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'ports' => [
            ['target' => 80, 'published' => '8080', 'protocol' => 'tcp'],
        ]]));
        $this->docker->setRunning([
            ['name' => 'whoami-whoami-1', 'project' => 'whoami', 'ports' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8080']]]],
        ]);

        $this->assertSame([], $this->check());
    }

    public function testSamePortOnUdpOrADifferentAddressIsFine(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'ports' => [
            ['target' => 53, 'published' => '5353', 'protocol' => 'udp'],
            ['target' => 80, 'published' => '8080', 'protocol' => 'tcp', 'host_ip' => '127.0.0.1'],
        ]]));
        $this->docker->setRunning([
            ['name' => 'other', 'project' => 'other', 'ports' => [
                '53/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '5353']],
                '80/tcp' => [['HostIp' => '192.168.1.5', 'HostPort' => '8080']],
            ]],
        ]);
        $this->assertSame([], $this->check());
    }

    public function testTwoServicesOfTheStackPublishingTheSamePortIsAProblem(): void
    {
        $this->docker->setConfig(['name' => 'whoami', 'services' => [
            'web' => ['image' => 'busybox', 'ports' => [['target' => 80, 'published' => '8080', 'protocol' => 'tcp']]],
            'api' => ['image' => 'busybox', 'ports' => [['target' => 80, 'published' => '8080', 'protocol' => 'tcp', 'host_ip' => '0.0.0.0']]],
            'dns' => ['image' => 'busybox', 'ports' => [['target' => 53, 'published' => '8080', 'protocol' => 'udp']]],
        ]]);

        $problems = $this->check();
        $this->assertCount(1, $problems);
        $this->assertSame("Services 'web' and 'api' both publish port 8080/tcp.", $problems[0]);
    }

    public function testOneServicePublishingTheSamePortTwiceIsAProblem(): void
    {
        $this->docker->setConfig($this->config(['image' => 'busybox', 'ports' => [
            ['target' => 80, 'published' => '8080', 'protocol' => 'tcp'],
            ['target' => 81, 'published' => '8080', 'protocol' => 'tcp', 'host_ip' => '127.0.0.1'],
        ]]));

        $this->assertSame(["Service 'whoami' publishes port 8080/tcp twice."], $this->check());
    }

    public function testPublishedRangeIsLeftToDocker(): void
    {
        // "6000-6010:6000" means any free port in the range.
        $this->docker->setConfig($this->config(['image' => 'busybox', 'ports' => [
            ['target' => 6000, 'published' => '6000-6010', 'protocol' => 'tcp'],
        ]]));
        $this->docker->setRunning([
            ['name' => 'other', 'project' => 'other', 'ports' => ['6005/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '6005']]]],
        ]);
        $this->assertSame([], $this->check());
    }

    public function testPortsOfAServiceOnlyOnAMacvlanNetworkAreNotChecked(): void
    {
        // Unraid's br0 is an external macvlan network; Docker publishes no ports there.
        $this->docker->addNetwork('br0', 'macvlan');
        $this->docker->setConfig([
            'name' => 'whoami',
            'services' => ['whoami' => ['image' => 'busybox', 'networks' => ['br0' => null], 'ports' => [
                ['target' => 80, 'published' => '80', 'protocol' => 'tcp'],
            ]]],
            'networks' => ['br0' => ['name' => 'br0', 'external' => true]],
        ]);
        $this->docker->setRunning([
            ['name' => 'nginx', 'project' => null, 'ports' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '80']]]],
        ]);
        $this->assertSame([], $this->check());
    }

    public function testPortsOfAServiceAlsoOnABridgeNetworkAreChecked(): void
    {
        $this->docker->addNetwork('br0', 'macvlan');
        $this->docker->setConfig([
            'name' => 'whoami',
            'services' => ['whoami' => ['image' => 'busybox', 'networks' => ['br0' => null, 'default' => null], 'ports' => [
                ['target' => 80, 'published' => '80', 'protocol' => 'tcp'],
            ]]],
            'networks' => ['br0' => ['name' => 'br0', 'external' => true], 'default' => ['name' => 'whoami_default']],
        ]);
        $this->docker->setRunning([
            ['name' => 'nginx', 'project' => null, 'ports' => ['80/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '80']]]],
        ]);
        $this->assertCount(1, $this->check());
    }

    // ----- helpers -----

    /**
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private function config(array $service): array
    {
        return ['name' => 'whoami', 'services' => ['whoami' => $service]];
    }

    /** @return string[] */
    private function check(bool $missingNetworksWillBeCreated = false): array
    {
        $check = new GitDeployCheck(
            'whoami',
            ['-f', $this->clone . '/whoami/compose.yaml'],
            $this->clone,
            $this->clone . '/whoami',
            $this->stackDir . '/.env',
            $missingNetworksWillBeCreated
        );
        return $check->run();
    }
}
