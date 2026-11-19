<?php

namespace Tests\Unit;

use App\Support\GanttScheduler;
use PHPUnit\Framework\TestCase;

final class GanttSchedulerTest extends TestCase
{
    public function testDependencyChainIsCritical(): void
    {
        $tasks = [
            ['id' => 1, 'start' => '2026-11-01', 'due' => '2026-11-02', 'milestone' => false],
            ['id' => 2, 'start' => '2026-11-01', 'due' => '2026-11-03', 'milestone' => false],
        ];
        $deps = [['task_id' => 2, 'depends_on_task_id' => 1]];

        $result = GanttScheduler::analyze($tasks, $deps);

        $this->assertTrue($result['critical'][1]);
        $this->assertTrue($result['critical'][2]);
        $this->assertSame(0, $result['slack'][2]);
    }

    public function testParallelTaskHasSlack(): void
    {
        $tasks = [
            ['id' => 1, 'start' => '2026-11-01', 'due' => '2026-11-05', 'milestone' => false],
            ['id' => 2, 'start' => '2026-11-01', 'due' => '2026-11-05', 'milestone' => false],
            ['id' => 3, 'start' => '2026-11-01', 'due' => '2026-11-01', 'milestone' => false],
        ];
        $deps = [
            ['task_id' => 2, 'depends_on_task_id' => 1],
        ];

        $result = GanttScheduler::analyze($tasks, $deps);

        $this->assertGreaterThan(0, $result['slack'][3]);
        $this->assertFalse($result['critical'][3]);
        $this->assertTrue($result['critical'][1]);
        $this->assertTrue($result['critical'][2]);
    }

    public function testMilestoneOnCriticalPath(): void
    {
        $tasks = [
            ['id' => 1, 'start' => '2026-11-01', 'due' => '2026-11-01', 'milestone' => false],
            ['id' => 2, 'start' => '2026-11-01', 'due' => '2026-11-01', 'milestone' => true],
        ];
        $deps = [['task_id' => 2, 'depends_on_task_id' => 1]];

        $result = GanttScheduler::analyze($tasks, $deps);

        $this->assertTrue($result['critical'][1]);
        $this->assertTrue($result['critical'][2]);
    }

    public function testTasksWithoutDatesAreIgnored(): void
    {
        $result = GanttScheduler::analyze([
            ['id' => 1, 'start' => null, 'due' => null, 'milestone' => false],
        ], []);

        $this->assertSame([], $result['critical']);
    }
}
