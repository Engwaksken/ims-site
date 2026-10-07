<?php
require_once __DIR__ . '/config.php';


if (!isset($_SESSION['user_id'])) {
    header("Location: ../login");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../my-activities");
    exit();
}

$uid    = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

if ($action !== 'update') {
    header("Location: ../my-activities");
    exit();
}


function clean(string $v): string
{
    return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8');
}

function safe_float($v): float
{
    return (float) filter_var($v, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
}

function safe_int($v): int
{
    return (int) filter_var($v, FILTER_SANITIZE_NUMBER_INT);
}

function redirect_error(string $msg, int $plan_id = 0): void
{
    $_SESSION['activity_error'] = $msg;
    $url = $plan_id > 0 ? "../edit-activity?id={$plan_id}" : "../my-activities";
    header("Location: {$url}");
    exit();
}

function redirect_success(string $msg, string $url = '../my-activities'): void
{
    $_SESSION['activity_success'] = $msg;
    header("Location: {$url}");
    exit();
}

/* ----------------------------------------------------------
   INPUTS
---------------------------------------------------------- */
$plan_id       = safe_int($_POST['plan_id'] ?? 0);
$is_submit     = isset($_POST['submit_plan']);
$status        = $is_submit ? 'submitted' : 'draft';

$fiscal_year   = safe_int($_POST['fiscal_year'] ?? date('Y'));
$category_id   = safe_int($_POST['category_id'] ?? 0);
$plan_title    = clean($_POST['plan_title'] ?? '');
$department_id = safe_int($_POST['department_id'] ?? 0);

if ($plan_id <= 0) {
    redirect_error('Invalid activity plan.');
}

/* ----------------------------------------------------------
   VERIFY PLAN OWNERSHIP + EDITABILITY
---------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT plan_id, user_id, status
    FROM activity_plans
    WHERE plan_id = ?
      AND user_id = ?
    LIMIT 1
");
if (!$stmt) {
    redirect_error('Unable to validate activity plan.', $plan_id);
}
$stmt->bind_param('ii', $plan_id, $uid);
$stmt->execute();
$existing_plan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$existing_plan) {
    redirect_error('Activity plan not found or access denied.', $plan_id);
}

if (!in_array(($existing_plan['status'] ?? 'draft'), ['draft', 'rejected'], true)) {
    redirect_error('Only draft or rejected activity plans can be edited.', $plan_id);
}

/* ----------------------------------------------------------
   VALIDATE HEADER
---------------------------------------------------------- */
if ($plan_title === '') {
    redirect_error('Plan title is required.', $plan_id);
}
if ($category_id <= 0) {
    redirect_error('Please select a category.', $plan_id);
}
if ($department_id <= 0) {
    redirect_error('Department could not be determined. Please complete your employee profile.', $plan_id);
}
if ($fiscal_year < 2000 || $fiscal_year > 2100) {
    redirect_error('Invalid fiscal year.', $plan_id);
}

/* ----------------------------------------------------------
   VALIDATE ACTIVITIES
---------------------------------------------------------- */
$activities_post = $_POST['activities'] ?? [];

if (empty($activities_post) || !is_array($activities_post)) {
    redirect_error('Please add at least one activity before saving.', $plan_id);
}

$activities   = [];
$total_weight = 0.0;

foreach ($activities_post as $raw_id => $act) {
    $description   = clean($act['description'] ?? '');
    $key_result    = clean($act['key_result'] ?? '');
    $weight        = safe_float($act['weight'] ?? 0);
    $unit          = clean($act['unit'] ?? '');
    $annual_target = safe_float($act['annual_target'] ?? 0);

    if ($description === '') {
        redirect_error('All activities must have a description.', $plan_id);
    }

    if ($key_result === '') {
        redirect_error("Activity \"{$description}\" must have a Key Result.", $plan_id);
    }

    if ($weight < 0 || $weight > 100) {
        redirect_error("Weight for \"{$description}\" must be between 0 and 100.", $plan_id);
    }

    $total_weight += $weight;

    $activities[] = [
        'post_id'       => (int)$raw_id,
        'description'   => $description,
        'key_result'    => $key_result,
        'weight'        => $weight,
        'unit'          => $unit,
        'annual_target' => $annual_target,
    ];
}


$weekly_post    = $_POST['weekly'] ?? [];
$valid_quarters = ['q1', 'q2', 'q3', 'q4'];
$weekly_data    = [];

foreach ($valid_quarters as $q) {
    $weekly_data[$q] = [];

    if (empty($weekly_post[$q]) || !is_array($weekly_post[$q])) {
        continue;
    }

    foreach ($weekly_post[$q] as $act_post_id => $weeks) {
        $act_post_id = (int)$act_post_id;

        if (!is_array($weeks)) {
            continue;
        }

        $weekly_data[$q][$act_post_id] = [];

        foreach ($weeks as $wkey => $wdata) {
            if (!preg_match('/^w(\d{1,2})$/', (string)$wkey, $m)) {
                continue;
            }

            $week_num = (int)$m[1];
            if ($week_num < 1 || $week_num > 12) {
                continue;
            }

            $actual  = isset($wdata['actual']) && $wdata['actual'] !== ''
                ? safe_float($wdata['actual'])
                : null;
            $comment = clean($wdata['comment'] ?? '');

            if ($actual !== null || $comment !== '') {
                $weekly_data[$q][$act_post_id][$week_num] = [
                    'actual'  => $actual,
                    'comment' => $comment,
                ];
            }
        }
    }
}

/* ----------------------------------------------------------
   DB TRANSACTION
---------------------------------------------------------- */
$conn->begin_transaction();

try {
    /* --  UPDATE PLAN HEADER -- */
    $stmt = $conn->prepare("
        UPDATE activity_plans
        SET
            department_id = ?,
            category_id   = ?,
            fiscal_year   = ?,
            plan_title    = ?,
            total_weight  = ?,
            status        = ?,
            updated_at    = NOW(),
            submitted_at  = CASE
                                WHEN ? = 'submitted' AND submitted_at IS NULL THEN NOW()
                                ELSE submitted_at
                            END
        WHERE plan_id = ?
          AND user_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new Exception("Plan update prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        'iiisdssii',
        $department_id,
        $category_id,
        $fiscal_year,
        $plan_title,
        $total_weight,
        $status,
        $status,
        $plan_id,
        $uid
    );
    $stmt->execute();
    $stmt->close();

    /* --  DELETE OLD WEEKLY ENTRIES -- */
    $stmt = $conn->prepare("
        DELETE FROM activity_weekly_entries
        WHERE plan_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Delete weekly entries prepare failed: " . $conn->error);
    }

    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $stmt->close();

    /* -- DELETE OLD ACTIVITIES -- */
    $stmt = $conn->prepare("
        DELETE FROM plan_activities
        WHERE plan_id = ?
    ");
    if (!$stmt) {
        throw new Exception("Delete activities prepare failed: " . $conn->error);
    }

    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $stmt->close();

    /* -- INSERT NEW ACTIVITIES -- */
    $act_stmt = $conn->prepare("
        INSERT INTO plan_activities
            (plan_id, sort_order, description, key_result, weight, unit_of_measure, annual_target, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$act_stmt) {
        throw new Exception("Insert activity prepare failed: " . $conn->error);
    }

    /* -- INSERT NEW WEEKLY ENTRIES -- */
    $week_stmt = $conn->prepare("
        INSERT INTO activity_weekly_entries
            (activity_id, plan_id, quarter, week_number, actual_value, comment, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$week_stmt) {
        throw new Exception("Insert weekly entry prepare failed: " . $conn->error);
    }

    foreach ($activities as $sort => $act) {
        $sort_order    = $sort + 1;
        $weight        = (float)$act['weight'];
        $annual_target = (float)$act['annual_target'];

        $act_stmt->bind_param(
            'iissdsd',
            $plan_id,
            $sort_order,
            $act['description'],
            $act['key_result'],
            $weight,
            $act['unit'],
            $annual_target
        );
        $act_stmt->execute();
        $activity_id = (int)$conn->insert_id;

        $post_id = $act['post_id'];

        foreach ($valid_quarters as $q) {
            if (empty($weekly_data[$q][$post_id])) {
                continue;
            }

            foreach ($weekly_data[$q][$post_id] as $week_num => $entry) {
                $actual_val  = $entry['actual'];
                $comment_val = $entry['comment'];

                $week_stmt->bind_param(
                    'iisids',
                    $activity_id,
                    $plan_id,
                    $q,
                    $week_num,
                    $actual_val,
                    $comment_val
                );
                $week_stmt->execute();
            }
        }
    }

    $act_stmt->close();
    $week_stmt->close();

    /* -- 6. COMMIT -- */
    $conn->commit();

    /* -- 7. NOTIFICATIONS -- */
    $mode_label = $is_submit ? 'updated and submitted for review' : 'updated as draft';

    if (function_exists('send_notification')) {
        send_notification(
            $uid,
            "Your Activity Plan \"{$plan_title}\" has been {$mode_label}.",
            $is_submit ? 'success' : 'info'
        );
    }

    if ($is_submit) {
        $sup_stmt = $conn->prepare("
            SELECT supervisor_id
            FROM employee_directory
            WHERE user_id = ?
              AND supervisor_id IS NOT NULL
            LIMIT 1
        ");
        if ($sup_stmt) {
            $sup_stmt->bind_param('i', $uid);
            $sup_stmt->execute();
            $sup_row = $sup_stmt->get_result()->fetch_assoc();
            $sup_stmt->close();

            if ($sup_row && (int)$sup_row['supervisor_id'] > 0 && function_exists('send_notification')) {
                $sup_id = (int)$sup_row['supervisor_id'];

                $name_stmt = $conn->prepare("
                    SELECT full_name
                    FROM users
                    WHERE user_id = ?
                    LIMIT 1
                ");
                $emp_name = 'An employee';

                if ($name_stmt) {
                    $name_stmt->bind_param('i', $uid);
                    $name_stmt->execute();
                    $name_row = $name_stmt->get_result()->fetch_assoc();
                    $name_stmt->close();

                    if ($name_row && !empty($name_row['full_name'])) {
                        $emp_name = $name_row['full_name'];
                    }
                }

                send_notification(
                    $sup_id,
                    "{$emp_name} has submitted an updated Activity Plan \"{$plan_title}\" for your review.",
                    'info',
                    "view-activity?id={$plan_id}"
                );
            }
        }
    }

    $msg = $is_submit
        ? "Activity Plan updated and submitted successfully! Your supervisor has been notified."
        : "Activity Plan draft updated successfully.";

    redirect_success($msg, "../view-activity?id={$plan_id}");

} catch (Throwable $e) {
    $conn->rollback();

    error_log('[activity-update-process] DB error for user ' . $uid . ', plan ' . $plan_id . ': ' . $e->getMessage());

    redirect_error(
        'A database error occurred while updating your activity plan. Please try again or contact IT support.',
        $plan_id
    );
}