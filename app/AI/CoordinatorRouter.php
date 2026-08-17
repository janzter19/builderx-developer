<?php
declare(strict_types=1);

namespace BuilderX\AI;

final class CoordinatorRouter
{
    public function __construct(private readonly AiSpecialistRegistry $registry)
    {
    }

    /** @return array<string, mixed> */
    public function route(string $action, string $stage, ?string $skill = null): array
    {
        $candidates = $this->registry->availableForStage($stage, $skill);
        if ($candidates === []) {
            return [
                'route_status' => 'registration_required',
                'action' => $action,
                'stage' => $stage,
                'requested_skill' => $skill,
                'registration_proposal' => [
                    'purpose' => 'No approved specialist currently matches this task.',
                    'stages' => [$stage],
                    'skills' => $skill !== null && $skill !== '' ? [$skill] : [],
                    'write_scope' => 'none',
                    'status' => 'pending_approval',
                ],
            ];
        }

        $selected = $candidates[0];
        return [
            'route_status' => 'routed',
            'action' => $action,
            'stage' => $stage,
            'requested_skill' => $skill,
            'specialist_key' => $selected['specialist_key'],
            'specialist' => $selected,
            'candidate_count' => count($candidates),
        ];
    }
}
