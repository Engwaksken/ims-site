<?php
declare(strict_types=1);

$page_title = 'My Tasks';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
check_role(IMS_STAFF_ROLES);

$uid = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = (($_SESSION['role'] ?? '') === 'Administrator');
$message = '';
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Invalid security token. Refresh the page and try again.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create') {
        $title = trim((string)($_POST['title'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $frequency = in_array($_POST['frequency'] ?? '', ['Daily', 'Weekly'], true) ? (string)$_POST['frequency'] : 'Daily';
        $taskDate = (string)($_POST['task_date'] ?? $today);
        $assignedTo = (int)($_POST['assigned_to'] ?? $uid);
        $kpiRef = trim((string)($_POST['kpi_ref'] ?? ''));
        $kpiSource = null; $kpiId = null; $kraId = null;
        if ($kpiRef !== '' && preg_match('/^(employee|appraisal):(\d+)(?::(\d+))?$/', $kpiRef, $matches)) {
            $kpiSource = $matches[1]; $kpiId = (int)$matches[2]; $kraId = isset($matches[3]) ? (int)$matches[3] : null;
        }
        $dateValid = DateTime::createFromFormat('!Y-m-d', $taskDate) !== false && date('Y-m-d', strtotime($taskDate)) === $taskDate;
        $assigneeCheck = $conn->prepare('SELECT user_id FROM users WHERE user_id = ? AND is_active = 1 LIMIT 1');
        $assigneeCheck->bind_param('i', $assignedTo); $assigneeCheck->execute();
        $assigneeExists = (bool)$assigneeCheck->get_result()->fetch_assoc(); $assigneeCheck->close();
        $kpiValid = true;
        if ($kpiSource === 'employee') {
            $sql = 'SELECT kpi_id FROM kpis WHERE kpi_id = ? AND user_id = ?';
            if (!$isAdmin) $sql .= ' AND user_id = ' . $uid;
            $check = $conn->prepare($sql); $check->bind_param('ii', $kpiId, $assignedTo); $check->execute();
            $kpiValid = (bool)$check->get_result()->fetch_assoc(); $check->close();
        } elseif ($kpiSource === 'appraisal') {
            $sql = 'SELECT ak.kpi_id FROM appraisal_kpis ak JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE ak.kpi_id=? AND ak.kra_id=? AND pa.user_id=?';
            $check = $conn->prepare($sql); $check->bind_param('iii', $kpiId, $kraId, $assignedTo); $check->execute();
            $kpiValid = (bool)$check->get_result()->fetch_assoc(); $check->close();
        }
        if ($title === '' || mb_strlen($title) > 240 || !$dateValid || !$assigneeExists || !$kpiValid) {
            $message = 'Please check the task title, date, assignee, and KPI/KRA selection.';
        } else {
            $insert = $conn->prepare('INSERT INTO employee_tasks (title,details,assigned_to,created_by,kpi_source,kpi_id,kra_id,task_frequency,task_date) VALUES (?,?,?,?,?,?,?,?,?)');
            $insert->bind_param('ssiisiiss', $title, $details, $assignedTo, $uid, $kpiSource, $kpiId, $kraId, $frequency, $taskDate);
            $insert->execute(); $insert->close();
            $newTaskId = (int)$conn->insert_id;
            if ($assignedTo !== $uid) {
                notify_user($assignedTo, 'A task was assigned to you', $title . ' · Due ' . $taskDate, 'info', $newTaskId, 'employee_task');
            }
            header('Location: my-tasks?view=' . urlencode((string)($_POST['return_view'] ?? 'daily'))); exit;
        }
    } elseif (in_array($action, ['status', 'move'], true)) {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $scope = $isAdmin ? '' : ' AND assigned_to = ' . $uid;
        if ($action === 'move') {
            $stmt = $conn->prepare("UPDATE employee_tasks SET moved_from=task_date,task_date=?,status='Pending' WHERE task_id=? AND status='Pending' $scope");
            $stmt->bind_param('si', $today, $taskId);
        } else {
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['Pending','In Progress','Completed'], true)) $status = 'Pending';
            $stmt = $conn->prepare("UPDATE employee_tasks SET status=?,completed_at=IF(?='Completed',NOW(),NULL) WHERE task_id=? $scope");
            $stmt->bind_param('ssi', $status, $status, $taskId);
        }
        $stmt->execute(); $stmt->close();
        header('Location: my-tasks?view=' . urlencode((string)($_POST['return_view'] ?? 'daily'))); exit;
    }
}

$view = in_array($_GET['view'] ?? 'daily', ['daily','weekly','past','all'], true) ? (string)($_GET['view'] ?? 'daily') : 'daily';
$selectedUser = $isAdmin ? (int)($_GET['user'] ?? 0) : $uid;
$where = $isAdmin ? '1=1' : 't.assigned_to=' . $uid;
if ($isAdmin && $selectedUser > 0) $where .= ' AND t.assigned_to=' . $selectedUser;
if ($view === 'daily') $where .= " AND t.task_date='" . $conn->real_escape_string($today) . "'";
elseif ($view === 'weekly') { $monday = date('Y-m-d', strtotime('monday this week')); $sunday = date('Y-m-d', strtotime('sunday this week')); $where .= " AND t.task_date BETWEEN '$monday' AND '$sunday'"; }
elseif ($view === 'past') $where .= " AND t.task_date<'$today'";

$tasks = [];
$sql = "SELECT t.*, assignee.full_name assignee_name, creator.full_name creator_name,
        CASE WHEN t.kpi_source='employee' THEN ek.kpi_title ELSE ak.title END AS kpi_title,
        CASE WHEN t.kpi_source='appraisal' THEN kr.title ELSE NULL END AS kra_title
        FROM employee_tasks t JOIN users assignee ON assignee.user_id=t.assigned_to
        JOIN users creator ON creator.user_id=t.created_by
        LEFT JOIN kpis ek ON t.kpi_source='employee' AND ek.kpi_id=t.kpi_id
        LEFT JOIN appraisal_kpis ak ON t.kpi_source='appraisal' AND ak.kpi_id=t.kpi_id
        LEFT JOIN appraisal_kras kr ON kr.kra_id=t.kra_id WHERE $where ORDER BY t.task_date DESC, t.status='Completed', t.created_at DESC";
$res = $conn->query($sql); if ($res) while ($row = $res->fetch_assoc()) $tasks[] = $row;

$kpis = [];
$sql = 'SELECT CONCAT("employee:",k.kpi_id) ref,k.kpi_title title,NULL kra_title,u.full_name owner_name FROM kpis k JOIN users u ON u.user_id=k.user_id WHERE 1=1';
if (!$isAdmin) $sql .= ' AND k.user_id=' . $uid;
$res = $conn->query($sql); if ($res) while ($row = $res->fetch_assoc()) $kpis[] = $row;
$appKpis = $conn->query('SELECT CONCAT("appraisal:",ak.kpi_id,":",ak.kra_id) ref,ak.title,kr.title kra_title,pa.full_name owner_name FROM appraisal_kpis ak JOIN appraisal_kras kr ON kr.kra_id=ak.kra_id JOIN performance_appraisals pa ON pa.appraisal_id=ak.appraisal_id WHERE pa.status IN ("draft","submitted","under_review","completed")' . ($isAdmin ? '' : ' AND pa.user_id=' . $uid));
if ($appKpis) while ($row = $appKpis->fetch_assoc()) $kpis[] = $row;
$users = [];
$res = $conn->query("SELECT user_id,full_name FROM users WHERE is_active=1 AND role NOT IN ('Member','Applicant','Donor/Partner') ORDER BY full_name"); if ($res) while ($row = $res->fetch_assoc()) $users[] = $row;

$counts = ['Pending'=>0,'In Progress'=>0,'Completed'=>0];
$countWhere = $isAdmin ? '1=1' : 'assigned_to=' . $uid;
$res = $conn->query("SELECT status,COUNT(*) total FROM employee_tasks WHERE $countWhere AND task_date='$today' GROUP BY status");
if ($res) while ($row = $res->fetch_assoc()) $counts[$row['status']] = (int)$row['total'];
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
include 'includes/header.php';
?>
<style>
.tasks-hero{display:flex;justify-content:space-between;gap:18px;align-items:center;padding:24px 28px;margin-bottom:18px;border-radius:14px;background:linear-gradient(120deg,#0f766e,#115e59);color:#fff}.tasks-hero h1{margin:0 0 6px;font-size:24px}.tasks-hero p{margin:0;color:#d1fae5}.task-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}.task-stat{padding:18px;border-radius:12px;background:#fff;border:1px solid #e2e8f0;box-shadow:0 2px 8px #0f172a0a}.task-stat strong{display:block;font-size:25px;color:#0f766e}.task-stat span{font-size:12px;color:#64748b}.task-tabs{display:flex;gap:4px;overflow:auto;border-bottom:1px solid #dbe3ec;margin:10px 0 16px}.task-tabs a{padding:11px 15px;text-decoration:none;color:#64748b;font-weight:700;white-space:nowrap;border-bottom:3px solid transparent}.task-tabs a.active{color:#0f766e;border-color:#0f766e}.task-row{padding:15px 0;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;gap:18px;align-items:center}.task-row:last-child{border:0}.task-title{font-weight:800;color:#0f172a}.task-meta{font-size:12px;color:#64748b;margin-top:5px}.task-controls{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.task-controls form{display:inline-flex;gap:5px}.task-status{border-radius:999px;background:#f1f5f9;padding:5px 9px;font-size:11px;font-weight:800;color:#475569}.task-status.completed{background:#dcfce7;color:#166534}.task-status.in-progress{background:#dbeafe;color:#1d4ed8}.task-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.task-form-grid .wide{grid-column:1/-1}@media(max-width:680px){.task-stats{grid-template-columns:1fr}.task-row{align-items:flex-start;flex-direction:column}.task-form-grid{grid-template-columns:1fr}.task-form-grid .wide{grid-column:auto}.tasks-hero{align-items:flex-start;flex-direction:column}}
</style>
<section class="tasks-hero"><div><h1><i class="fas fa-list-check"></i> <?= $isAdmin && $view === 'all' ? 'Team Tasks' : 'My Tasks' ?></h1><p>Daily actions and weekly priorities linked to your KPI and KRA commitments.</p></div><button class="btn" style="background:#fff;color:#0f766e" onclick="document.getElementById('createTask').scrollIntoView({behavior:'smooth'})"><i class="fas fa-plus"></i> Add task</button></section>
<div class="task-stats"><div class="task-stat"><strong><?= $counts['Pending'] ?></strong><span>Pending today</span></div><div class="task-stat"><strong><?= $counts['In Progress'] ?></strong><span>In progress today</span></div><div class="task-stat"><strong><?= $counts['Completed'] ?></strong><span>Completed today</span></div></div>
<nav class="task-tabs" aria-label="Task periods">
<?php foreach (['daily'=>'Today','weekly'=>'This Week','past'=>'Past Tasks'] as $key=>$label): ?><a class="<?= $view===$key?'active':'' ?>" href="my-tasks?view=<?= $key ?>"><?= $label ?></a><?php endforeach; ?>
<?php if ($isAdmin): ?><a class="<?= $view==='all'?'active':'' ?>" href="my-tasks?view=all">Everyone</a><?php endif; ?></nav>
<?php if ($isAdmin && $view==='all'): ?><form method="get" class="card card-body" style="margin-bottom:16px"><input type="hidden" name="view" value="all"><label for="taskUser">Filter by staff member</label><select id="taskUser" name="user" class="form-control" onchange="this.form.submit()"><option value="0">Everyone</option><?php foreach($users as $u): ?><option value="<?= (int)$u['user_id'] ?>" <?= $selectedUser===(int)$u['user_id']?'selected':'' ?>><?= $e($u['full_name']) ?></option><?php endforeach ?></select></form><?php endif; ?>
<div class="card"><div class="card-header"><h3><i class="fas fa-calendar-check"></i> <?= ['daily'=>'Today','weekly'=>'This Week','past'=>'Past Tasks','all'=>'Everyone’s Tasks'][$view] ?></h3><span><?= count($tasks) ?> task<?= count($tasks)===1?'':'s' ?></span></div><div class="card-body">
<?php if (!$tasks): ?><div class="text-center" style="padding:28px;color:#64748b">No tasks in this period.</div><?php else: foreach($tasks as $task): ?><article class="task-row"><div><div class="task-title"><?= $e($task['title']) ?></div><div class="task-meta"><?= $e($task['task_date']) ?> · <?= $e($task['task_frequency']) ?><?php if ($isAdmin): ?> · Assigned to <?= $e($task['assignee_name']) ?><?php endif; ?><?php if ($task['kpi_title']): ?> · KPI: <?= $e($task['kpi_title']) ?><?php endif; ?><?php if ($task['kra_title']): ?> · KRA: <?= $e($task['kra_title']) ?><?php endif; ?><?php if ($task['details']): ?><br><?= $e($task['details']) ?><?php endif; ?></div></div><div class="task-controls"><span class="task-status <?= strtolower(str_replace(' ','-',$task['status'])) ?>"><?= $e($task['status']) ?></span><?php if ($isAdmin || (int)$task['assigned_to']===$uid): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="task_id" value="<?= (int)$task['task_id'] ?>"><input type="hidden" name="return_view" value="<?= $e($view) ?>"><input type="hidden" name="action" value="status"><select class="form-control" name="status" aria-label="Update task status" onchange="this.form.submit()"><?php foreach(['Pending','In Progress','Completed'] as $s): ?><option <?= $task['status']===$s?'selected':'' ?>><?= $s ?></option><?php endforeach ?></select></form><?php if ($task['status']==='Pending' && $task['task_date']<$today): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="task_id" value="<?= (int)$task['task_id'] ?>"><input type="hidden" name="return_view" value="<?= $e($view) ?>"><input type="hidden" name="action" value="move"><button class="btn btn-sm btn-primary">Move to today</button></form><?php endif; ?><?php endif; ?></div></article><?php endforeach; endif; ?></div></div>
<div class="card" id="createTask" style="margin-top:18px"><div class="card-header"><h3><i class="fas fa-plus-circle"></i> Create or Assign a Task</h3></div><div class="card-body"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><input type="hidden" name="return_view" value="<?= $e($view) ?>"><div class="task-form-grid"><div class="form-group wide"><label for="taskTitle" class="required">Task</label><input id="taskTitle" name="title" maxlength="240" class="form-control" required></div><div class="form-group wide"><label for="taskDetails">Details</label><textarea id="taskDetails" name="details" class="form-control" rows="2"></textarea></div><div class="form-group"><label for="frequency">Frequency</label><select id="frequency" name="frequency" class="form-control"><option>Daily</option><option>Weekly</option></select></div><div class="form-group"><label for="taskDate">Date</label><input id="taskDate" type="date" name="task_date" value="<?= $today ?>" class="form-control" required></div><div class="form-group"><label for="assignedTo">Assign to</label><select id="assignedTo" name="assigned_to" class="form-control" required><?php foreach($users as $u): ?><option value="<?= (int)$u['user_id'] ?>" <?= (int)$u['user_id']===$uid?'selected':'' ?>><?= $e($u['full_name']) ?></option><?php endforeach ?></select></div><div class="form-group"><label for="kpiRef">Linked KPI / KRA</label><select id="kpiRef" name="kpi_ref" class="form-control"><option value="">No KPI link</option><?php foreach($kpis as $k): ?><option value="<?= $e($k['ref']) ?>"><?= $e($k['title']) ?><?= $k['kra_title']?' — KRA: '.$e($k['kra_title']):'' ?><?= $isAdmin?' · '.$e($k['owner_name']):'' ?></option><?php endforeach ?></select></div></div><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save task</button></form></div></div>
<?php if ($message): ?><script>window.alert(<?= json_encode($message) ?>);</script><?php endif; ?>
<?php include 'includes/footer.php'; ?>
