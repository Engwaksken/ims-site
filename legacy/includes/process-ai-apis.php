<?php
/**
 * includes/process-ai-apis.php
 * Handles all POST actions for AI API management.
 * Include this at the top of manage-ai-apis.php (after session_start / config).
 */

declare(strict_types=1);

// -- Helpers ------------------------------------------------------------------

function aiFlash(string $type, string $message): void
{
    $_SESSION[$type] = $message;
}

function aiRedirect(string $url = 'manage-ai-apis'): never
{
    header('Location: ' . $url);
    exit;
}

function aiPostStr(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function aiRequireCsrf(): void
{
    if (
        empty($_POST['csrf']) ||
        empty($_SESSION['csrf_ai_api']) ||
        !hash_equals($_SESSION['csrf_ai_api'], (string)$_POST['csrf'])
    ) {
        aiFlash('error', 'Invalid request token. Please try again.');
        aiRedirect();
    }
}

// -- Queries -------------------------------------------------------------------

function aiFetchAll(mysqli $conn): array
{
    $rows = [];
    $res  = $conn->query("
        SELECT
            ai_api_id, provider_name, api_name, model_name,
            api_endpoint, api_key_last4, temperature, max_tokens,
            timeout_seconds, is_default, is_active, notes,
            created_at, updated_at
        FROM ai_api
        ORDER BY is_default DESC, is_active DESC, provider_name ASC, api_name ASC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }

    return $rows;
}

function aiFetchOne(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare("
        SELECT ai_api_id, provider_name, api_name, model_name,
               api_endpoint, api_key_last4, temperature, max_tokens,
               timeout_seconds, is_default, is_active, notes
        FROM ai_api WHERE ai_api_id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function aiSetDefault(mysqli $conn, int $id, int $userId): void
{
    if ($id <= 0) {
        throw new RuntimeException('Invalid AI API selected.');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE ai_api SET is_default = 0, updated_by = ?, updated_at = NOW()");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE ai_api SET is_default = 1, is_active = 1, updated_by = ?, updated_at = NOW()
            WHERE ai_api_id = ?
        ");
        $stmt->bind_param('ii', $userId, $id);
        $stmt->execute();

        if ($stmt->affected_rows < 1) {
            $stmt->close();
            throw new RuntimeException('AI API not found.');
        }

        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

// -- Action dispatcher ---------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return; // nothing to process on GET
}

aiRequireCsrf();

/** @var AiReviewService $service  (already constructed in the parent page) */
/** @var mysqli          $conn     (already available from config) */
/** @var int             $userId   (already set from session)       */

$action = aiPostStr('action');

try {

    // -- SAVE (insert or update) -----------------------------------------------
    if ($action === 'save') {
        $id          = (int)($_POST['ai_api_id'] ?? 0);
        $provider    = aiPostStr('provider_name');
        $apiName     = aiPostStr('api_name');
        $model       = aiPostStr('model_name');
        $endpoint    = aiPostStr('api_endpoint');
        $apiKey      = aiPostStr('api_key');
        $temperature = (float)($_POST['temperature'] ?? 0.3);
        $maxTokens   = max(100, (int)($_POST['max_tokens']       ?? 4000));
        $timeout     = max(10,  (int)($_POST['timeout_seconds']  ?? 60));
        $isDefault   = isset($_POST['is_default']) ? 1 : 0;
        $isActive    = isset($_POST['is_active'])  ? 1 : 0;
        $notes       = aiPostStr('notes');

        if ($provider === '' || $apiName === '' || $model === '') {
            throw new RuntimeException('Provider, API name, and model name are required.');
        }
        if ($temperature < 0 || $temperature > 2) {
            throw new RuntimeException('Temperature must be between 0 and 2.');
        }
        if ($id <= 0 && $apiKey === '') {
            throw new RuntimeException('API key is required when creating a new AI API.');
        }

        // -- UPDATE ------------------------------------------------------------
        if ($id > 0) {
            if (!aiFetchOne($conn, $id)) {
                throw new RuntimeException('AI API record not found.');
            }

            if ($apiKey !== '') {
                $enc   = $service->encryptKey($apiKey);
                $last4 = substr($apiKey, -4);

                $stmt = $conn->prepare("
                    UPDATE ai_api
                    SET provider_name=?, api_name=?, model_name=?, api_endpoint=?,
                        api_key_encrypted=?, api_key_last4=?,
                        temperature=?, max_tokens=?, timeout_seconds=?,
                        is_default=?, is_active=?, notes=?,
                        updated_by=?, updated_at=NOW()
                    WHERE ai_api_id=?
                ");
                $stmt->bind_param(
                    'ssssssdiiiisii',
                    $provider, $apiName, $model, $endpoint,
                    $enc, $last4,
                    $temperature, $maxTokens, $timeout,
                    $isDefault, $isActive, $notes,
                    $userId, $id
                );
            } else {
                $stmt = $conn->prepare("
                    UPDATE ai_api
                    SET provider_name=?, api_name=?, model_name=?, api_endpoint=?,
                        temperature=?, max_tokens=?, timeout_seconds=?,
                        is_default=?, is_active=?, notes=?,
                        updated_by=?, updated_at=NOW()
                    WHERE ai_api_id=?
                ");
                $stmt->bind_param(
                    'ssssdiiiisii',
                    $provider, $apiName, $model, $endpoint,
                    $temperature, $maxTokens, $timeout,
                    $isDefault, $isActive, $notes,
                    $userId, $id
                );
            }

            $stmt->execute();
            $stmt->close();

            if ($isDefault) {
                aiSetDefault($conn, $id, $userId);
            }

            aiFlash('success', 'AI API updated successfully.');
            aiRedirect();
        }

        // -- INSERT ------------------------------------------------------------
        $enc   = $service->encryptKey($apiKey);
        $last4 = substr($apiKey, -4);

        $stmt = $conn->prepare("
            INSERT INTO ai_api
                (provider_name, api_name, model_name, api_endpoint,
                 api_key_encrypted, api_key_last4,
                 temperature, max_tokens, timeout_seconds,
                 is_default, is_active, notes,
                 created_by, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->bind_param(
            'ssssssdiiiisii',
            $provider, $apiName, $model, $endpoint,
            $enc, $last4,
            $temperature, $maxTokens, $timeout,
            $isDefault, $isActive, $notes,
            $userId, $userId
        );
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();

        if ($isDefault) {
            aiSetDefault($conn, $newId, $userId);
        }

        aiFlash('success', 'AI API added successfully.');
        aiRedirect();
    }

    // -- TOGGLE active ---------------------------------------------------------
    if ($action === 'toggle') {
        $id = (int)($_POST['ai_api_id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid AI API selected.');
        }

        $stmt = $conn->prepare("
            UPDATE ai_api
            SET is_active  = IF(is_active = 1, 0, 1),
                updated_by = ?,
                updated_at = NOW()
            WHERE ai_api_id = ?
        ");
        $stmt->bind_param('ii', $userId, $id);
        $stmt->execute();
        $stmt->close();

        aiFlash('success', 'AI API status updated.');
        aiRedirect();
    }

    // -- SET DEFAULT -----------------------------------------------------------
    if ($action === 'default') {
        $id = (int)($_POST['ai_api_id'] ?? 0);
        aiSetDefault($conn, $id, $userId);
        aiFlash('success', 'Default AI API updated.');
        aiRedirect();
    }

    // -- DELETE ----------------------------------------------------------------
    if ($action === 'delete') {
        $id = (int)($_POST['ai_api_id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid AI API selected.');
        }

        $used = 0;
        $check = $conn->query("SHOW TABLES LIKE 'ai_application_reviews'");
        if ($check && $check->num_rows > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) AS c FROM ai_application_reviews WHERE ai_api_id = ?
            ");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $used = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
        }

        if ($used > 0) {
            $stmt = $conn->prepare("
                UPDATE ai_api
                SET is_active = 0, is_default = 0,
                    updated_by = ?, updated_at = NOW()
                WHERE ai_api_id = ?
            ");
            $stmt->bind_param('ii', $userId, $id);
            $stmt->execute();
            $stmt->close();
            aiFlash('success', 'This API has review history — it was deactivated instead of deleted.');
        } else {
            $stmt = $conn->prepare("DELETE FROM ai_api WHERE ai_api_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            aiFlash('success', 'AI API deleted successfully.');
        }

        aiRedirect();
    }

    throw new RuntimeException('Unknown action.');

} catch (Throwable $e) {
    aiFlash('error', $e->getMessage());
    aiRedirect();
}