<?php
require_once 'includes/config.php';
check_login();
check_role(['Administrator', 'Programs Lead']);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    // Load project details for modal
    $project_id = intval($_GET['id']);
    $result = $conn->query("SELECT * FROM projects WHERE project_id = $project_id");
    $project = $result->fetch_assoc();
    if (!$project) {
        echo "<p>Project not found.</p>";
        exit;
    }

    // Render edit form
    ?>
    <form id="editProjectForm" action="project-edit" method="POST">
        <input type="hidden" name="project_id" value="<?= $project['project_id']; ?>">
        <?php include __DIR__.'/project-form-fields.php'; ?>
        <button type="submit" class="btn btn-primary">Update Project</button>
    </form>
    <?php
    exit;
}

header('Content-Type: application/json');
$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = intval($_POST['project_id'] ?? 0);
    $project_name = sanitize_input($_POST['project_name'] ?? '');
    $project_code = sanitize_input($_POST['project_code'] ?? '');
    $donor_id = intval($_POST['donor_id'] ?? 0);
    $value_chain = sanitize_input($_POST['value_chain'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $budget = floatval($_POST['budget'] ?? 0);
    $currency = sanitize_input($_POST['currency'] ?? 'UGX');
    $status = sanitize_input($_POST['status'] ?? 'Pending');
    $geographic_scope = sanitize_input($_POST['geographic_scope'] ?? '');
    $objectives = sanitize_input($_POST['objectives'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');

    if (!$project_id || empty($project_name) || empty($donor_id) || empty($start_date) || empty($end_date) || empty($budget)) {
        $response['message'] = 'Please fill all required fields.';
        echo json_encode($response);
        exit;
    }

    $stmt = $conn->prepare("UPDATE projects SET project_name=?, project_code=?, donor_id=?, value_chain=?, start_date=?, end_date=?, budget=?, currency=?, status=?, geographic_scope=?, objectives=?, description=? WHERE project_id=?");
    $stmt->bind_param('ssissdssssssi', $project_name, $project_code, $donor_id, $value_chain, $start_date, $end_date, $budget, $currency, $status, $geographic_scope, $objectives, $description, $project_id);

    if ($stmt->execute()) {
        $result = $conn->query("SELECT p.*, d.donor_name,
            (SELECT COUNT(*) FROM project_beneficiaries pb WHERE pb.project_id = p.project_id) as beneficiary_count,
            (SELECT COUNT(*) FROM indicators i WHERE i.project_id = p.project_id) as indicator_count
            FROM projects p LEFT JOIN donors d ON p.donor_id = d.donor_id
            WHERE p.project_id = $project_id");
        $project = $result->fetch_assoc();
        $project['budget_formatted'] = format_currency($project['budget'], $project['currency']);

        $response['success'] = true;
        $response['project'] = $project;

        log_action($_SESSION['user_id'], 'Edit Project', 'projects', $project_id, 'Project updated');
    } else {
        $response['message'] = 'Database error: ' . $stmt->error;
    }

    echo json_encode($response);
    exit;
}

$response['message'] = 'Invalid request.';
echo json_encode($response);
