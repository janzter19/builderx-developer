<?php
declare(strict_types=1);

require_once __DIR__ . '/app/foundation.php';

function bx_portal_redirect(): void
{
    header('Location: ./');
    exit;
}

function bx_portal_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Read and reconcile one task through the same server-side path used by the
 * normal JSON status endpoint and the SSE status stream.
 *
 * @return array<string, mixed>|null
 */
function bx_ai_task_status_read(
    string $taskId,
    string $userKey,
    \BuilderX\AI\AiTaskStore $taskStore,
    \BuilderX\AI\CommunicationMessageStore $communication,
    bool $allowPhasePersistence = false
): ?array {
    $task = $taskStore->find($taskId, $userKey);
    if ($task === null) {
        return null;
    }

    $task = (new \BuilderX\AI\AiTaskResultReconciler(
        $communication,
        $taskStore
    ))->reconcile((string) $task['task_id']);
    if ($task === null) {
        return null;
    }

    if ($allowPhasePersistence && ($task['status'] ?? '') === 'completed') {
        $input = is_array($task['input'] ?? null) ? $task['input'] : [];
        $sourceSnapshot = is_array($input['source_snapshot'] ?? null) ? $input['source_snapshot'] : [];
        $phaseKey = trim((string) ($input['phase_key'] ?? ''));
        if ($phaseKey === '' && is_array($input['context_refs'] ?? null)) {
            foreach ($input['context_refs'] as $reference) {
                if (is_string($reference) && str_starts_with($reference, 'phase:')) {
                    $phaseKey = trim(substr($reference, 6));
                    break;
                }
            }
        }
        if ($sourceSnapshot === [] && is_string($input['text'] ?? null)) {
            $marker = 'Tab 1 context JSON:';
            $markerPosition = strpos($input['text'], $marker);
            if ($markerPosition !== false) {
                $legacySnapshot = json_decode(trim(substr($input['text'], $markerPosition + strlen($marker))), true);
                if (is_array($legacySnapshot)) {
                    $sourceSnapshot = $legacySnapshot;
                }
            }
        }
        if ($phaseKey !== '' && is_array($task['output'] ?? null)) {
            try {
                $persistence = (new \BuilderX\AI\PhaseBuilderNarrativeCleanupStore())->persist(
                    $phaseKey,
                    $task['output'],
                    $sourceSnapshot,
                    $userKey
                );
                $task['phase_builder_persistence'] = $persistence;
            } catch (Throwable $error) {
                $task['phase_builder_persistence'] = [
                    'status' => 'failed',
                    'message' => 'The Desktop result was received, but the phase draft was not changed.',
                    'details' => $error->getMessage(),
                ];
            }
        }
    }

    $task['delivery_status'] = 'queued';
    foreach ([
        'inbox' => 'queued',
        'locks' => 'received',
        'processed' => 'processed',
        'failed' => 'failed',
    ] as $folder => $deliveryStatus) {
        if ($communication->read((string) $task['task_id'], $folder) !== null) {
            $task['delivery_status'] = $deliveryStatus;
            break;
        }
    }
    if ((string) ($task['status'] ?? '') === 'running') {
        $task['delivery_status'] = 'received';
    }

    return $task;
}

function bx_portal_clean_text(string $value, int $maxLength): string
{
    return substr(trim(preg_replace('/\s+/', ' ', $value) ?: ''), 0, $maxLength);
}

function bx_portal_date_or_null(string $value, array &$errors, string $label): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $errors[] = "{$label} must use YYYY-MM-DD format.";
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        $errors[] = "{$label} is not a valid date.";
        return null;
    }

    return $value;
}

function bx_portal_array_rows(string $key): array
{
    $rows = $_POST[$key] ?? [];
    return is_array($rows) ? array_values($rows) : [];
}

function bx_portal_family_payload_from_post(): array
{
    $errors = [];
    $member = [
        'first_name' => bx_portal_clean_text((string) ($_POST['first_name'] ?? ''), 80),
        'middle_name' => bx_portal_clean_text((string) ($_POST['middle_name'] ?? ''), 80),
        'last_name' => bx_portal_clean_text((string) ($_POST['last_name'] ?? ''), 80),
        'suffix' => bx_portal_clean_text((string) ($_POST['suffix'] ?? ''), 40),
        'birth_date' => bx_portal_date_or_null((string) ($_POST['birth_date'] ?? ''), $errors, 'Birth date'),
        'relationship_to_user' => bx_portal_clean_text((string) ($_POST['relationship_to_user'] ?? ''), 80),
        'contact_email' => bx_portal_clean_text((string) ($_POST['contact_email'] ?? ''), 190),
        'contact_phone' => bx_portal_clean_text((string) ($_POST['contact_phone'] ?? ''), 40),
        'consent_privacy' => isset($_POST['consent_privacy']) ? 1 : 0,
        'consent_contact' => isset($_POST['consent_contact']) ? 1 : 0,
    ];

    if ($member['first_name'] === '') {
        $errors[] = 'First name is required.';
    }
    if ($member['last_name'] === '') {
        $errors[] = 'Last name is required.';
    }
    if ($member['relationship_to_user'] === '') {
        $errors[] = 'Relationship is required.';
    }
    if ($member['contact_email'] !== '' && !filter_var($member['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Contact email is invalid.';
    }
    if ($member['contact_phone'] !== '' && !preg_match('/^[0-9+().\-\s]{3,40}$/', $member['contact_phone'])) {
        $errors[] = 'Contact phone contains unsupported characters.';
    }
    if ($member['consent_privacy'] !== 1) {
        $errors[] = 'Privacy consent is required before saving a family member.';
    }

    $vehicles = [];
    $plates = [];
    foreach (bx_portal_array_rows('vehicles') as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $vehicle = [
            'plate_number' => strtoupper(bx_portal_clean_text((string) ($row['plate_number'] ?? ''), 40)),
            'make' => bx_portal_clean_text((string) ($row['make'] ?? ''), 80),
            'model' => bx_portal_clean_text((string) ($row['model'] ?? ''), 80),
            'model_year' => trim((string) ($row['model_year'] ?? '')),
            'color' => bx_portal_clean_text((string) ($row['color'] ?? ''), 60),
            'ownership_type' => bx_portal_clean_text((string) ($row['ownership_type'] ?? ''), 80),
            'registration_status' => bx_portal_clean_text((string) ($row['registration_status'] ?? ''), 80),
        ];
        if (implode('', $vehicle) === '') {
            continue;
        }
        if ($vehicle['plate_number'] === '') {
            $errors[] = 'Vehicle plate number is required when adding a vehicle.';
        }
        if ($vehicle['ownership_type'] === '') {
            $errors[] = 'Vehicle ownership type is required when adding a vehicle.';
        }
        if ($vehicle['model_year'] !== '') {
            if (!ctype_digit($vehicle['model_year']) || (int) $vehicle['model_year'] < 1900 || (int) $vehicle['model_year'] > ((int) date('Y') + 1)) {
                $errors[] = 'Vehicle model year is outside the allowed range.';
            } else {
                $vehicle['model_year'] = (string) (int) $vehicle['model_year'];
            }
        } else {
            $vehicle['model_year'] = null;
        }
        $plateKey = strtolower($vehicle['plate_number']);
        if ($plateKey !== '' && isset($plates[$plateKey])) {
            $errors[] = 'Duplicate vehicle plate numbers are not allowed in one registration.';
        }
        $plates[$plateKey] = true;
        $vehicles[] = $vehicle;
    }

    $educationRows = [];
    $educationDuplicates = [];
    foreach (bx_portal_array_rows('education') as $row) {
        if (!is_array($row)) {
            continue;
        }

        $dateErrors = [];
        $education = [
            'education_level' => bx_portal_clean_text((string) ($row['education_level'] ?? ''), 80),
            'institution_name' => bx_portal_clean_text((string) ($row['institution_name'] ?? ''), 190),
            'program_name' => bx_portal_clean_text((string) ($row['program_name'] ?? ''), 190),
            'date_started' => bx_portal_date_or_null((string) ($row['date_started'] ?? ''), $dateErrors, 'Education start date'),
            'date_completed' => bx_portal_date_or_null((string) ($row['date_completed'] ?? ''), $dateErrors, 'Education completion date'),
            'completion_status' => bx_portal_clean_text((string) ($row['completion_status'] ?? ''), 80),
        ];
        $errors = array_merge($errors, $dateErrors);
        if (implode('', array_map(static fn ($item): string => (string) $item, $education)) === '') {
            continue;
        }
        if ($education['education_level'] === '') {
            $errors[] = 'Education level is required when adding education history.';
        }
        if ($education['institution_name'] === '') {
            $errors[] = 'Institution name is required when adding education history.';
        }
        if ($education['completion_status'] === '') {
            $errors[] = 'Completion status is required when adding education history.';
        }
        if ($education['date_started'] && $education['date_completed'] && strcmp($education['date_started'], $education['date_completed']) > 0) {
            $errors[] = 'Education start date cannot be after completion date.';
        }
        $duplicateKey = strtolower($education['education_level'] . '|' . $education['institution_name'] . '|' . $education['program_name'] . '|' . (string) $education['date_started']);
        if (isset($educationDuplicates[$duplicateKey])) {
            $errors[] = 'Duplicate education history rows are not allowed in one registration.';
        }
        $educationDuplicates[$duplicateKey] = true;
        $educationRows[] = $education;
    }

    return ['member' => $member, 'vehicles' => $vehicles, 'education' => $educationRows, 'errors' => $errors];
}

function bx_portal_save_family_member(array $user): bool
{
    $memberKey = trim((string) ($_POST['member_key'] ?? ''));
    $payload = bx_portal_family_payload_from_post();
    $member = $payload['member'];
    $errors = $payload['errors'];
    $ownerKey = (string) $user['user_key'];

    if ($memberKey !== '') {
        $existing = bx_db()->GetRow(
            "SELECT member_key FROM builder_family_member WHERE member_key = ? AND owner_user_key = ? AND member_status <> 'DELETED'",
            [$memberKey, $ownerKey]
        );
        if (!$existing) {
            bx_audit('UNAUTHORIZED', 'builder_family_member', null, ['requested_member_key' => $memberKey], 'Portal update rejected because the member is not owned by the signed-in user.');
            bx_flash('You are not authorized to edit that family member.', 'error');
            return false;
        }
    }

    $duplicateParams = [
        $ownerKey,
        $member['first_name'],
        $member['last_name'],
        $member['relationship_to_user'],
        $member['birth_date'],
        $member['birth_date'],
    ];
    $duplicateWhere = "owner_user_key = ? AND member_status <> 'DELETED' AND LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND LOWER(relationship_to_user) = LOWER(?) AND ((birth_date IS NULL AND ? IS NULL) OR birth_date = ?)";
    if ($memberKey !== '') {
        $duplicateWhere .= ' AND member_key <> ?';
        $duplicateParams[] = $memberKey;
    }
    if ((int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_family_member WHERE {$duplicateWhere}", $duplicateParams) > 0) {
        $errors[] = 'A matching family member already exists for your account.';
    }

    foreach ($payload['vehicles'] as $vehicle) {
        if ($vehicle['plate_number'] === '') {
            continue;
        }
        $vehicleParams = [$ownerKey, $vehicle['plate_number']];
        $vehicleWhere = "owner_user_key = ? AND vehicle_status <> 'DELETED' AND LOWER(plate_number) = LOWER(?)";
        if ($memberKey !== '') {
            $vehicleWhere .= ' AND member_key <> ?';
            $vehicleParams[] = $memberKey;
        }
        if ((int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_family_member_vehicle WHERE {$vehicleWhere}", $vehicleParams) > 0) {
            $errors[] = 'Vehicle plate ' . $vehicle['plate_number'] . ' is already assigned to another family member in your account.';
        }
    }

    if ($errors) {
        bx_flash(implode(' ', array_unique($errors)), 'error');
        return false;
    }

    $db = bx_db();
    $db->StartTrans();
    try {
        $isUpdate = $memberKey !== '';
        if (!$isUpdate) {
            $memberKey = bx_uuid();
            $db->Execute(
                "INSERT INTO builder_family_member (
                    member_key, owner_user_key, first_name, middle_name, last_name, suffix, birth_date,
                    relationship_to_user, contact_email, contact_phone, consent_privacy, consent_contact,
                    member_created_by_key, member_updated_by_key
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $memberKey,
                    $ownerKey,
                    $member['first_name'],
                    $member['middle_name'] ?: null,
                    $member['last_name'],
                    $member['suffix'] ?: null,
                    $member['birth_date'],
                    $member['relationship_to_user'],
                    $member['contact_email'] ?: null,
                    $member['contact_phone'] ?: null,
                    $member['consent_privacy'],
                    $member['consent_contact'],
                    $ownerKey,
                    $ownerKey,
                ]
            );
        } else {
            $db->Execute(
                "UPDATE builder_family_member
                SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, birth_date = ?,
                    relationship_to_user = ?, contact_email = ?, contact_phone = ?, consent_privacy = ?,
                    consent_contact = ?, member_updated_by_key = ?
                WHERE member_key = ? AND owner_user_key = ? AND member_status <> 'DELETED'",
                [
                    $member['first_name'],
                    $member['middle_name'] ?: null,
                    $member['last_name'],
                    $member['suffix'] ?: null,
                    $member['birth_date'],
                    $member['relationship_to_user'],
                    $member['contact_email'] ?: null,
                    $member['contact_phone'] ?: null,
                    $member['consent_privacy'],
                    $member['consent_contact'],
                    $ownerKey,
                    $memberKey,
                    $ownerKey,
                ]
            );
            $db->Execute(
                "UPDATE builder_family_member_vehicle
                SET vehicle_status = 'DELETED', vehicle_deleted_at = CURRENT_TIMESTAMP, vehicle_deleted_by_key = ?
                WHERE member_key = ? AND owner_user_key = ? AND vehicle_status <> 'DELETED'",
                [$ownerKey, $memberKey, $ownerKey]
            );
            $db->Execute(
                "UPDATE builder_family_member_education
                SET education_status = 'DELETED', education_deleted_at = CURRENT_TIMESTAMP, education_deleted_by_key = ?
                WHERE member_key = ? AND owner_user_key = ? AND education_status <> 'DELETED'",
                [$ownerKey, $memberKey, $ownerKey]
            );
        }

        foreach ($payload['vehicles'] as $vehicle) {
            $db->Execute(
                "INSERT INTO builder_family_member_vehicle (
                    vehicle_key, member_key, owner_user_key, plate_number, make, model, model_year,
                    color, ownership_type, registration_status, vehicle_created_by_key, vehicle_updated_by_key
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    bx_uuid(),
                    $memberKey,
                    $ownerKey,
                    $vehicle['plate_number'],
                    $vehicle['make'] ?: null,
                    $vehicle['model'] ?: null,
                    $vehicle['model_year'],
                    $vehicle['color'] ?: null,
                    $vehicle['ownership_type'],
                    $vehicle['registration_status'] ?: null,
                    $ownerKey,
                    $ownerKey,
                ]
            );
        }

        foreach ($payload['education'] as $education) {
            $db->Execute(
                "INSERT INTO builder_family_member_education (
                    education_key, member_key, owner_user_key, education_level, institution_name,
                    program_name, date_started, date_completed, completion_status, education_created_by_key, education_updated_by_key
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    bx_uuid(),
                    $memberKey,
                    $ownerKey,
                    $education['education_level'],
                    $education['institution_name'],
                    $education['program_name'] ?: null,
                    $education['date_started'],
                    $education['date_completed'],
                    $education['completion_status'],
                    $ownerKey,
                    $ownerKey,
                ]
            );
        }

        bx_audit($isUpdate ? 'UPDATE' : 'CREATE', 'builder_family_member', $memberKey, [
            'vehicle_count' => count($payload['vehicles']),
            'education_count' => count($payload['education']),
        ], $isUpdate ? 'User Portal family member updated.' : 'User Portal family member created.');
    } catch (Throwable $exception) {
        $db->FailTrans();
        bx_flash('Family member could not be saved. Please review the form and try again.', 'error');
        bx_audit('ERROR', 'builder_family_member', $memberKey ?: null, ['error' => $exception->getMessage()], 'User Portal family member save failed.');
        return false;
    } finally {
        $db->CompleteTrans();
    }

    bx_flash($member['first_name'] . ' ' . $member['last_name'] . ' was saved.', 'success');
    return true;
}

function bx_portal_family_members(?array $user): array
{
    if (!$user) {
        return [];
    }

    $ownerKey = (string) $user['user_key'];
    $members = bx_db()->GetAll(
        "SELECT
            member_key, first_name, middle_name, last_name, suffix, birth_date, relationship_to_user,
            contact_email, contact_phone, consent_privacy, consent_contact, member_status,
            member_created_at, member_updated_at,
            (SELECT COUNT(*) FROM builder_family_member_vehicle v WHERE v.member_key = m.member_key AND v.owner_user_key = m.owner_user_key AND v.vehicle_status <> 'DELETED') AS vehicle_count,
            (SELECT COUNT(*) FROM builder_family_member_education e WHERE e.member_key = m.member_key AND e.owner_user_key = m.owner_user_key AND e.education_status <> 'DELETED') AS education_count
        FROM builder_family_member m
        WHERE owner_user_key = ? AND member_status <> 'DELETED'
        ORDER BY member_updated_at DESC, x_id DESC",
        [$ownerKey]
    ) ?: [];

    foreach ($members as &$member) {
        $member['vehicles'] = bx_db()->GetAll(
            "SELECT vehicle_key, plate_number, make, model, model_year, color, ownership_type, registration_status
            FROM builder_family_member_vehicle
            WHERE member_key = ? AND owner_user_key = ? AND vehicle_status <> 'DELETED'
            ORDER BY x_id ASC",
            [$member['member_key'], $ownerKey]
        ) ?: [];
        $member['education'] = bx_db()->GetAll(
            "SELECT education_key, education_level, institution_name, program_name, date_started, date_completed, completion_status
            FROM builder_family_member_education
            WHERE member_key = ? AND owner_user_key = ? AND education_status <> 'DELETED'
            ORDER BY COALESCE(date_started, '9999-12-31') ASC, x_id ASC",
            [$member['member_key'], $ownerKey]
        ) ?: [];
    }
    unset($member);

    return $members;
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST') {
    bx_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $currentUserForAction = bx_current_user();

    if ($action === 'login_portal') {
        if (bx_login(trim((string) ($_POST['login'] ?? '')), (string) ($_POST['password'] ?? ''))) {
            bx_flash('Signed in to the User Portal.', 'success');
        } else {
            bx_flash('Invalid user portal login or password.', 'error');
        }
        bx_portal_redirect();
    }

    if ($action === 'logout_portal') {
        bx_logout();
        bx_flash('Signed out of the User Portal.', 'success');
        bx_portal_redirect();
    }

    if ($action === 'create_ai_task') {
        if (!$currentUserForAction) {
            bx_portal_json_response(['ok' => false, 'message' => 'Sign in before using an AI task.'], 401);
        }

        $task = null;
        try {
            $text = trim((string) ($_POST['text'] ?? ''));
            $taskStore = new \BuilderX\AI\AiTaskStore();
            $route = (new \BuilderX\AI\CoordinatorRouter(new \BuilderX\AI\AiSpecialistRegistry()))->route('rephrase_text', 'Validate', 'grammar');
            if (($route['route_status'] ?? '') !== 'routed') {
                bx_portal_json_response(['ok' => false, 'message' => 'No approved rephrase specialist is available.', 'data' => ['registration_proposal' => $route['registration_proposal'] ?? null]], 409);
            }
            $task = $taskStore->create(
                'rephrase_text',
                'Validate',
                (string) $route['specialist_key'],
                ['text' => $text, 'style_profile' => 'clear-and-correct', 'context_refs' => [], 'target_chat_id' => builderxConfigValue('codex_chat_id')],
                ['write_scope' => 'communication_only', 'allowed_paths' => []],
                null,
                null,
                (string) $currentUserForAction['user_key']
            );

            $communication = new \BuilderX\AI\CommunicationMessageStore(
                __DIR__ . '/storage/codex-communication'
            );
            $communication->write([
                'message_id' => (string) $task['task_id'],
                'correlation_id' => (string) $task['correlation_id'],
                'message_type' => 'ai_task',
                'direction' => 'builderx_to_codex',
                'sender' => 'builderx',
                'recipient' => 'codex_desktop',
                'status' => 'queued',
                'payload' => [
                    'task_id' => $task['task_id'],
                    'correlation_id' => $task['correlation_id'],
                    'action' => $task['action'],
                    'stage' => $task['stage'],
                    'specialist' => $task['specialist'],
                    'status' => $task['status'],
                    'input' => $task['input'],
                    'permissions' => $task['permissions'],
                    'target_chat_id' => $task['input']['target_chat_id'] ?? null,
                    'attempt' => $task['attempt'],
                ],
            ], 'inbox');

            bx_portal_json_response([
                'ok' => true,
                'data' => [
                    'task_id' => $task['task_id'],
                    'status' => $task['status'],
                ],
            ], 202);
        } catch (Throwable $exception) {
            if (is_array($task) && !empty($task['task_id'])) {
                try {
                    $taskStore->transition((string) $task['task_id'], 'failed', null, [
                        'code' => 'dispatch_failed',
                        'message' => 'The task could not be dispatched to Codex Desktop.',
                        'retryable' => true,
                    ]);
                } catch (Throwable) {
                    // Preserve the original safe response if failure persistence also fails.
                }
            }
            bx_portal_json_response(['ok' => false, 'message' => 'The AI task could not be queued.'], 503);
        }
    }

    if (in_array($action, ['propose_specialist', 'approve_specialist', 'propose_memory', 'approve_memory'], true)) {
        if (!$currentUserForAction || !bx_is_admin($currentUserForAction)) {
            bx_portal_json_response(['ok' => false, 'message' => 'Administrator approval is required for Coordinator management.'], 403);
        }

        try {
            if ($action === 'propose_specialist') {
                $csv = static function (string $value): array {
                    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
                };
                $proposal = (new \BuilderX\AI\AiSpecialistRegistry())->propose(
                    (string) ($_POST['specialist_key'] ?? ''),
                    (string) ($_POST['specialist_name'] ?? ''),
                    (string) ($_POST['specialist_purpose'] ?? ''),
                    $csv((string) ($_POST['specialist_stages'] ?? 'Validate')),
                    $csv((string) ($_POST['specialist_skills'] ?? '')),
                    $csv((string) ($_POST['specialist_tools'] ?? 'read_files')),
                    (string) ($_POST['specialist_write_scope'] ?? 'none'),
                    $csv((string) ($_POST['specialist_rag_scopes'] ?? '')),
                    isset($_POST['specialist_temporary']),
                    ['submitted_via' => 'coordinator-management'],
                    (string) $currentUserForAction['user_key']
                );
                bx_portal_json_response(['ok' => true, 'data' => ['specialist' => $proposal]], 201);
            }

            if ($action === 'approve_specialist') {
                $approved = (new \BuilderX\AI\AiSpecialistRegistry())->approve((string) ($_POST['specialist_key'] ?? ''), 'phase-manager-ui-' . (string) $currentUserForAction['user_key']);
                bx_portal_json_response(['ok' => true, 'data' => ['specialist' => $approved]]);
            }

            if ($action === 'propose_memory') {
                $memory = (new \BuilderX\AI\MemoryStore())->propose(
                    (string) ($_POST['memory_title'] ?? ''),
                    (string) ($_POST['memory_content'] ?? ''),
                    (string) ($_POST['memory_type'] ?? 'instruction'),
                    ['keyword', 'hybrid', 'metadata'],
                    array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['memory_tags'] ?? ''))), static fn (string $item): bool => $item !== '')),
                    ['submitted_via' => 'coordinator-management', 'project' => 'current'],
                    'coordinator-management',
                    null,
                    (string) $currentUserForAction['user_key']
                );
                bx_portal_json_response(['ok' => true, 'data' => ['memory' => $memory]], 201);
            }

            $approvedMemory = (new \BuilderX\AI\MemoryStore())->approve((string) ($_POST['memory_id'] ?? ''), (string) $currentUserForAction['user_key']);
            bx_portal_json_response(['ok' => true, 'data' => ['memory' => $approvedMemory]]);
        } catch (InvalidArgumentException $error) {
            bx_portal_json_response(['ok' => false, 'message' => $error->getMessage()], 422);
        } catch (Throwable) {
            bx_portal_json_response(['ok' => false, 'message' => 'The Coordinator management action could not be completed.'], 500);
        }
    }

    if ($action === 'save_family_member') {
        if (!$currentUserForAction) {
            bx_flash('Sign in before managing family members.', 'error');
            bx_portal_redirect();
        }

    bx_portal_save_family_member($currentUserForAction);
    bx_portal_redirect();
    }
}

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'ai_task_status') {
    $currentUserForStatus = bx_current_user();
    if (!$currentUserForStatus) {
        bx_portal_json_response(['ok' => false, 'message' => 'Sign in before reading an AI task.'], 401);
    }

    $taskId = trim((string) ($_GET['task_id'] ?? ''));
    try {
        $taskStore = new \BuilderX\AI\AiTaskStore();
        $communication = new \BuilderX\AI\CommunicationMessageStore(__DIR__ . '/storage/codex-communication');
        $task = bx_ai_task_status_read($taskId, (string) $currentUserForStatus['user_key'], $taskStore, $communication, bx_is_admin($currentUserForStatus));
    } catch (Throwable) {
        $task = null;
    }
    if (!$task) {
        bx_portal_json_response(['ok' => false, 'message' => 'The AI task was not found.'], 404);
    }

    bx_portal_json_response(['ok' => true, 'data' => ['task' => $task]]);
}

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'ai_task_status_stream') {
    $currentUserForStream = bx_current_user();
    if (!$currentUserForStream) {
        bx_portal_json_response(['ok' => false, 'message' => 'Sign in before reading an AI task.'], 401);
    }

    $taskId = trim((string) ($_GET['task_id'] ?? ''));
    $taskStore = new \BuilderX\AI\AiTaskStore();
    $communication = new \BuilderX\AI\CommunicationMessageStore(__DIR__ . '/storage/codex-communication');
    try {
        // The owner check happens before the stream starts, so an invalid task
        // never becomes a long-lived authenticated connection.
        $task = bx_ai_task_status_read($taskId, (string) $currentUserForStream['user_key'], $taskStore, $communication, bx_is_admin($currentUserForStream));
    } catch (Throwable) {
        $task = null;
    }
    if ($task === null) {
        bx_portal_json_response(['ok' => false, 'message' => 'The AI task was not found.'], 404);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    set_time_limit(30);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    $lastFingerprint = '';
    $lastHeartbeatAt = microtime(true);
    $deadline = microtime(true) + 25.0;
    while (microtime(true) < $deadline) {
        try {
            $nextTask = bx_ai_task_status_read($taskId, (string) $currentUserForStream['user_key'], $taskStore, $communication, bx_is_admin($currentUserForStream));
        } catch (Throwable) {
            $nextTask = null;
        }

        if ($nextTask === null) {
            echo "event: error\ndata: {\"message\":\"The AI task status could not be read.\"}\n\n";
            flush();
            break;
        }

        $fingerprint = hash('sha256', (string) json_encode([
            'status' => $nextTask['status'] ?? null,
            'delivery_status' => $nextTask['delivery_status'] ?? null,
            'output' => $nextTask['output'] ?? null,
            'error' => $nextTask['error'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($fingerprint !== $lastFingerprint) {
            echo "event: task\ndata: " . json_encode($nextTask, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
            $lastFingerprint = $fingerprint;
        }

        if (in_array((string) ($nextTask['status'] ?? ''), ['completed', 'failed', 'cancelled'], true)) {
            break;
        }
        if (microtime(true) - $lastHeartbeatAt >= 5.0) {
            echo ": keepalive\n\n";
            flush();
            $lastHeartbeatAt = microtime(true);
        }
        if (connection_aborted()) {
            break;
        }
        usleep(250000);
    }
    exit;
}

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'ai_specialists') {
    $currentUserForDirectory = bx_current_user();
    if (!$currentUserForDirectory || !bx_is_admin($currentUserForDirectory)) {
        bx_portal_json_response(['ok' => false, 'message' => 'Administrator access is required.'], 403);
    }
    bx_portal_json_response([
        'ok' => true,
        'data' => [
            'specialists' => (new \BuilderX\AI\AiSpecialistRegistry())->listAll(),
            'memories' => (new \BuilderX\AI\MemoryStore())->listRecent(),
        ],
    ]);
}

$softwareName = bx_setting('software_name', 'BuilderX');
$hasAdministrator = bx_count('builder_user') > 0;
$currentUser = bx_current_user();
$isAdmin = $currentUser ? bx_is_admin($currentUser) : false;
$flash = bx_take_flash();
$projectBasePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$projectBasePath = ($projectBasePath === '' ? '' : $projectBasePath) . '/';
$portalMode = builderxConfigValue('portal_mode');
$localConfigPath = __DIR__ . '/phases/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (!is_array($localConfig) || !array_key_exists('portal_mode', $localConfig)) {
    $portalMode = $projectBasePath === '/my_builderx_project24/' ? 'product' : 'starter';
}
if ($portalMode === '') {
    $portalMode = 'starter';
}

$manifestPath = __DIR__ . '/frontend/dist/.vite/manifest.json';
$manifest = file_exists($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
$entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
$assetsBase = './frontend/dist/';

$payload = [
    'csrf' => bx_csrf_token(),
    'softwareName' => $softwareName,
    'projectBasePath' => $projectBasePath,
    'portalMode' => $portalMode !== '' ? $portalMode : 'product',
    'hasAdministrator' => $hasAdministrator,
    'isAdmin' => $isAdmin,
    'flash' => $flash,
    'currentUser' => $currentUser ?: null,
    'familyMembers' => bx_portal_family_members($currentUser ?: null),
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bx_h($softwareName) ?></title>
    <?php if ($entry && !empty($entry['css'])): ?>
        <?php foreach ($entry['css'] as $css): ?>
            <link rel="stylesheet" href="<?= bx_h($assetsBase . $css) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <script>
        window.__BUILDERX_PORTAL__ = <?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
</head>
<body>
    <div id="root">
        <?php if (!$entry): ?>
            <main style="max-width: 760px; margin: 40px auto; font-family: Arial, Helvetica, sans-serif;">
                <h1><?= bx_h($softwareName) ?></h1>
                <p>The shared React frontend is not built yet. Run <code>npm run build</code> in <code>frontend</code>.</p>
            </main>
        <?php endif; ?>
    </div>
    <?php if ($entry): ?>
        <script type="module" src="<?= bx_h($assetsBase . $entry['file']) ?>"></script>
    <?php endif; ?>
</body>
</html>
