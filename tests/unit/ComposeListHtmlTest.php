<?php

/**
 * Unit Tests for Compose List HTML Structure
 * 
 * Tests the HTML output structure in ComposeList.php, verifying column classes,
 * update column selector, expand arrow spacing, and autostart cell class.
 * These are source-level tests that verify the PHP template markup.
 */

declare(strict_types=1);

namespace ComposeManager\Tests;

use PluginTests\TestCase;

class ComposeListHtmlTest extends TestCase
{
    private string $listPagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listPagePath = __DIR__ . '/../../source/compose.manager/include/ComposeList.php';
        $this->assertFileExists($this->listPagePath, 'ComposeList.php must exist');
    }

    private function getPageSource(): string
    {
        return file_get_contents($this->listPagePath);
    }

    // ===========================================
    // Update Column Class Tests
    // ===========================================

    public function testUpdateCellHasCorrectClass(): void
    {
        $source = $this->getPageSource();
        // The update cell class must include 'compose-updatecolumn' (not 'updatecolumn')
        $this->assertStringContainsString('compose-updatecolumn', $source);
    }

    public function testUpdateCellDoesNotUseBareUpdateColumn(): void
    {
        $source = $this->getPageSource();
        // Ensure we don't have the unprefixed class that would conflict with Docker tab
        $this->assertStringNotContainsString("class='updatecolumn'", $source);
    }

    // ===========================================
    // Expand Arrow Tests
    // ===========================================

    public function testExpandArrowIconClassExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('expand-icon', $source);
        $this->assertStringContainsString('fa-chevron-right', $source);
    }

    // ===========================================
    // Autostart Cell Tests
    // ===========================================

    public function testAutostartCellHasClassNine(): void
    {
        $source = $this->getPageSource();
        // Autostart cell includes class 'nine' for CSS targeting
        $this->assertMatchesRegularExpression("/class='[^']*\\bnine\\b[^']*'/", $source);
    }

    public function testAutostartCheckboxHasDataAttribute(): void
    {
        $source = $this->getPageSource();
        // Autostart checkbox should have data-scriptName for the stack
        $this->assertStringContainsString("class='auto_start'", $source);
        $this->assertStringContainsString('data-scriptName', $source);
    }

    // ===========================================
    // Stack Row Structure Tests
    // ===========================================

    public function testStackRowHasSortableClass(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("class='compose-sortable'", $source);
    }

    public function testStackRowHasDataAttributes(): void
    {
        $source = $this->getPageSource();
        // Stack rows must have data attributes for JS interaction
        $this->assertStringContainsString("data-project=", $source);
        $this->assertStringContainsString("data-projectname=", $source);
        $this->assertStringContainsString("data-isup=", $source);
        $this->assertStringContainsString("data-ctids=", $source);
    }

    // ===========================================
    // Toggleable Column Markup Tests
    // ===========================================

    public function testToggleableColumnsUseColumnClasses(): void
    {
        $source = $this->getPageSource();
        // Toggleable columns should use concrete column classes.
        $this->assertMatchesRegularExpression("/class='[^']*\\bcol-description\\b[^']*'/", $source);
        $this->assertMatchesRegularExpression("/class='[^']*\\bcol-path\\b[^']*'/", $source);
    }

    public function testToggleableMetricColumnsMarkupExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString("col-cpu compose-load-cell", $source);
        $this->assertStringContainsString("col-memory compose-load-cell", $source);
        $this->assertStringContainsString("compose-stack-cpu-", $source);
        $this->assertStringContainsString("compose-stack-mem-", $source);
        $this->assertStringContainsString("compose-stack-cpu-bar-", $source);
        $this->assertStringContainsString("compose-stack-mem-bar-", $source);
    }

    // ===========================================
    // Container Count Display Tests
    // ===========================================

    public function testContainerCountDisplayExists(): void
    {
        $source = $this->getPageSource();
        // Container count uses running/total format
        $this->assertStringContainsString('$runningCount', $source);
        $this->assertStringContainsString('$containerCount', $source);
    }

    // ===========================================
    // Empty State Tests
    // ===========================================

    public function testNoStacksMessageExists(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('No Docker Compose stacks found', $source);
        $this->assertStringContainsString('Add New Stack', $source);
        $this->assertStringContainsString("colspan='14'", $source);
    }

    // ===========================================
    // Loading Spinner Tests
    // ===========================================

    public function testLoadingSpinnerInDetailRow(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('fa-spinner fa-spin compose-spinner', $source);
        $this->assertStringContainsString('Loading containers...', $source);
    }

    // ===========================================
    // Status Icon Tests
    // ===========================================

    public function testStatusIconHasDataAttribute(): void
    {
        $source = $this->getPageSource();
        // Status icon should have data-status for debugging
        $this->assertStringContainsString("data-status='", $source);
        $this->assertStringContainsString('compose-status-icon', $source);
    }

    // ===========================================
    // Regression Tests
    // ===========================================

    /**
     * Regression: Container ID/name collection must use $stackInfo->getContainerList(),
     * not an undefined $projectContainers variable. Without this, data-ctids is empty
     * and CPU/memory stats don't display on page load.
     */
    public function testContainerIterationUsesStackInfoGetContainerList(): void
    {
        $source = $this->getPageSource();
        $this->assertStringContainsString('$stackInfo->getContainerList()', $source,
            'Container loop must use $stackInfo->getContainerList(), not $projectContainers');
        $this->assertStringNotContainsString('$projectContainers', $source,
            '$projectContainers is undefined in ComposeList.php — use $stackInfo->getContainerList()');
    }
}
