<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * Cálculo del camino crítico para el cronograma (CPM simplificado).
 * Usa las fechas planificadas como restricción de inicio y las dependencias
 * (task_id depende de depends_on_task_id) para calcular holguras.
 */
final class GanttScheduler
{
    /**
     * @param array<int, array{id:int,start:?string,due:?string,milestone:bool}> $tasks
     * @param array<int, array{task_id:int,depends_on_task_id:int}> $dependencies
     * @return array{critical:array<int,bool>,slack:array<int,int>}
     */
    public static function analyze(array $tasks, array $dependencies): array
    {
        $byId = [];
        foreach ($tasks as $task) {
            if (!empty($task['start']) && !empty($task['due'])) {
                $byId[(int)$task['id']] = $task;
            }
        }

        $ids = array_keys($byId);
        if ($ids === []) {
            return ['critical' => [], 'slack' => []];
        }

        $adj = [];
        $indegree = [];
        $predecessors = [];
        foreach ($ids as $id) {
            $adj[$id] = [];
            $indegree[$id] = 0;
            $predecessors[$id] = [];
        }

        foreach ($dependencies as $dependency) {
            $taskId = (int)$dependency['task_id'];
            $dependsOn = (int)$dependency['depends_on_task_id'];
            if (!isset($byId[$taskId], $byId[$dependsOn])) {
                continue;
            }
            $adj[$dependsOn][] = $taskId;
            $predecessors[$taskId][] = $dependsOn;
            $indegree[$taskId]++;
        }

        // Orden topológico (Kahn). Las dependencias circulares ya se impiden al crearlas.
        $queue = array_values(array_filter($ids, static fn(int $id): bool => $indegree[$id] === 0));
        $order = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $order[] = $id;
            foreach ($adj[$id] as $next) {
                if (--$indegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }
        foreach ($ids as $id) {
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $duration = static function (array $task): int {
            if (!empty($task['milestone'])) {
                return 0;
            }
            $start = self::day($task['start']);
            $due = self::day($task['due']);
            return max(1, $due - $start + 1);
        };

        $earliestStart = [];
        $earliestFinish = [];
        foreach ($order as $id) {
            $task = $byId[$id];
            $start = self::day($task['start']);
            foreach ($predecessors[$id] as $pred) {
                if (isset($earliestFinish[$pred])) {
                    $start = max($start, $earliestFinish[$pred] + 1);
                }
            }
            $earliestStart[$id] = $start;
            $earliestFinish[$id] = $start + max(0, $duration($task) - 1);
        }

        $projectFinish = max($earliestFinish);
        $successors = [];
        foreach ($ids as $id) {
            $successors[$id] = [];
        }
        foreach ($adj as $from => $list) {
            foreach ($list as $to) {
                $successors[$from][] = $to;
            }
        }

        $latestStart = [];
        $latestFinish = [];
        foreach (array_reverse($order) as $id) {
            $task = $byId[$id];
            $finish = $projectFinish;
            foreach ($successors[$id] as $next) {
                if (isset($latestStart[$next])) {
                    $finish = min($finish, $latestStart[$next] - 1);
                }
            }
            $latestFinish[$id] = $finish;
            $latestStart[$id] = $finish - max(0, $duration($task) - 1);
        }

        $critical = [];
        $slack = [];
        foreach ($ids as $id) {
            $value = max(0, $latestStart[$id] - $earliestStart[$id]);
            $slack[$id] = $value;
            $critical[$id] = $value === 0;
        }

        return ['critical' => $critical, 'slack' => $slack];
    }

    private static function day(string $date): int
    {
        return (int)floor((new DateTimeImmutable($date . ' 00:00:00'))->getTimestamp() / 86400);
    }
}
