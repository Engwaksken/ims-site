<?php
require_once 'includes/config.php';
check_login();
check_role(['Administrator', 'Programs Lead']);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    // Load program details for modal
    $program_id = intval($_GET['id']);
    $result = $conn->query("SELECT * FROM programs WHERE program_id = $program_id");
    $program = $result->fetch_assoc();
    if (!$program) {
        echo "<p>program not found.</p>";
        exit;
    }

    // Render edit form
    ?>
    <form id="editprogramForm" action="program-edit" method="POST">
        <input type="hidden" name="program_id" value="<?= $program['program_id']; ?>">
        <?php include __DIR__.'/program-form-fields.php'; ?>
        <button type="submit" class="btn btn-primary">Update program</button>
    </form>
    <?php
    exit;
}

header('Content-Type: application/json');
$response = ['success' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $program_id = intval($_POST['program_id'] ?? 0);
    $program_name = sanitize_input($_POST['program_name'] ?? '');
    $program_code = sanitize_input($_POST['program_code'] ?? '');
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

    if (!$program_id || empty($program_name) || empty($donor_id) || empty($start_date) || empty($end_date) || empty($budget)) {
        $response['message'] = 'Please fill all required fields.';
        echo json_encode($response);
        exit;
    }

    $stmt = $conn->prepare("UPDATE programs SET program_name=?, program_code=?, donor_id=?, value_chain=?, start_date=?, end_date=?, budget=?, currency=?, status=?, geographic_scope=?, objectives=?, description=? WHERE program_id=?");
    $stmt->bind_param('ssissdssssssi', $program_name, $program_code, $donor_id, $value_chain, $start_date, $end_date, $budget, $currency, $status, $geographic_scope, $objectives, $description, $program_id);

    if ($stmt->execute()) {
        $result = $conn->query("SELECT p.*, d.donor_name,
            (SELECT COUNT(*) FROM program_beneficiaries pb WHERE pb.program_id = p.program_id) as beneficiary_count,
            (SELECT COUNT(*) FROM indicators i WHERE i.program_id = p.program_id) as indicator_count
            FROM programs p LEFT JOIN donors d ON p.donor_id = d.donor_id
            WHERE p.program_id = $program_id");
        $program = $result->fetch_assoc();
        $program['budget_formatted'] = format_currency($program['budget'], $program['currency']);

        $response['success'] = true;
        $response['program'] = $program;

        log_action($_SESSION['user_id'], 'Edit program', 'programs', $program_id, 'program updated');
    } else {
        $response['message'] = 'Database error: ' . $stmt->error;
    }

    echo json_encode($response);
    exit;
}

$response['message'] = 'Invalid request.';
echo json_encode($response);
