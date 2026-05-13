<?php

declare(strict_types=1);

/**
 * Maps rescue_cases (+ joined request) to timeline / progress for the user dashboard.
 */
final class UserCaseTracking
{
    /**
     * @param array<string, mixed> $row Case row with optional request_ai_status, assigned_rescuer_id, req_rescuer_id
     * @return array{
     *   variant: string,
     *   percent: int,
     *   current_index: int,
     *   steps: list<array{key: string, label: string, done: bool, active: bool}>
     * }
     */
    public static function fromRow(array $row): array
    {
        $status = (string) ($row['status'] ?? '');
        $assigned = !empty($row['assigned_rescuer_id']) || !empty($row['req_rescuer_id']);
        $aiRejected = (($row['request_ai_status'] ?? '') === 'Rejected');

        $steps = [
            ['key' => 'pending', 'label' => 'Pending', 'done' => false, 'active' => false],
            ['key' => 'assigned', 'label' => 'Rescuer assigned', 'done' => false, 'active' => false],
            ['key' => 'accepted', 'label' => 'Accepted', 'done' => false, 'active' => false],
            ['key' => 'completed', 'label' => 'Completed', 'done' => false, 'active' => false],
        ];

        if ($status === 'rejected' || ($aiRejected && $status !== 'resolved')) {
            foreach ($steps as $i => $_) {
                $steps[$i]['done'] = false;
                $steps[$i]['active'] = false;
            }
            return [
                'variant' => 'rejected',
                'percent' => 100,
                'current_index' => -1,
                'steps' => $steps,
            ];
        }

        if ($status === 'resolved') {
            foreach ($steps as $i => $_) {
                $steps[$i]['done'] = true;
                $steps[$i]['active'] = false;
            }
            return ['variant' => 'resolved', 'percent' => 100, 'current_index' => 3, 'steps' => $steps];
        }

        if ($status === 'accepted') {
            $steps[0]['done'] = true;
            $steps[1]['done'] = true;
            $steps[2]['done'] = true;
            $steps[2]['active'] = true;
            $steps[3]['done'] = false;
            return ['variant' => 'progress', 'percent' => 75, 'current_index' => 2, 'steps' => $steps];
        }

        // pending
        if ($assigned) {
            $steps[0]['done'] = true;
            $steps[1]['done'] = true;
            $steps[1]['active'] = true;
            return ['variant' => 'progress', 'percent' => 50, 'current_index' => 1, 'steps' => $steps];
        }

        $steps[0]['done'] = false;
        $steps[0]['active'] = true;
        return ['variant' => 'progress', 'percent' => 25, 'current_index' => 0, 'steps' => $steps];
    }

    /** @param array<string, mixed> $row */
    public static function priorityBadge(array $row): array
    {
        $p = (string) ($row['priority_level'] ?? 'low');
        return match ($p) {
            'urgent', 'critical' => ['class' => 'prio--critical', 'label' => 'Critical'],
            'high' => ['class' => 'prio--high', 'label' => 'High'],
            'medium' => ['class' => 'prio--medium', 'label' => 'Medium'],
            default => ['class' => 'prio--normal', 'label' => 'Normal'],
        };
    }
}
