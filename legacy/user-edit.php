<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
check_role(['Administrator']);

$user_id = (int)($_GET['id'] ?? 0);

if ($user_id <= 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'message'=>'Invalid user ID']);
    } else {
        echo "<p>Invalid user.</p>";
    }
    exit;
}

/* =========================================================
   GET → LOAD EDIT FORM (MODAL)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user) {
        echo "<p>User not found.</p>";
        exit;
    }
    ?>
    <form id="editUserForm" action="user-edit?id=<?= $user_id ?>" method="POST">

        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" class="form-control"
                   value="<?= htmlspecialchars($user['username']) ?>" required>
        </div>

        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" class="form-control"
                   value="<?= htmlspecialchars($user['full_name']) ?>" required>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" class="form-control"
                   value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>

        <div class="form-group">
            <label>Role</label>
            <select name="role" class="form-control" required>
                <?php require_once __DIR__ . '/includes/auth.php'; ?>
                <?php foreach (IMS_ALL_ROLES as $roleOption): ?>
                    <option value="<?= h($roleOption) ?>" <?= ($user['role'] ?? '') === $roleOption ? 'selected' : '' ?>><?= h($roleOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" class="form-control"
                   value="<?= htmlspecialchars($user['phone']) ?>">
        </div>

        <div class="form-group">
            <label>Status</label>
            <select name="is_active" class="form-control">
                <option value="1" <?= $user['is_active'] ? 'selected':'' ?>>Active</option>
                <option value="0" <?= !$user['is_active'] ? 'selected':'' ?>>Inactive</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Update User</button>
    </form>
    <?php
    exit;
}

/* =========================================================
   POST → UPDATE USER (AJAX)
   ========================================================= */
header('Content-Type: application/json');

$response = ['success'=>false];

$username  = trim((string)($_POST['username'] ?? ''));
$full_name = trim((string)($_POST['full_name'] ?? ''));
$email     = trim((string)($_POST['email'] ?? ''));
$role      = trim((string)($_POST['role'] ?? ''));
$phone     = trim($_POST['phone'] ?? '');
$is_active = (int)($_POST['is_active'] ?? 1);
if (!in_array($role, IMS_ALL_ROLES, true) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success'=>false,'message'=>'Invalid role or email address']);
    exit;
}
$is_active = $is_active === 1 ? 1 : 0;
// Prevent an administrator from locking themselves out.
if ($user_id === (int)$_SESSION['user_id'] && ($is_active === 0 || $role !== 'Administrator')) {
    echo json_encode(['success'=>false,'message'=>'You cannot deactivate or demote your own account']);
    exit;
}
/* ---- Duplicate check (excluding current user) ---- */
$check = $conn->prepare(
    "SELECT user_id FROM users 
     WHERE (username = ? OR email = ?) AND user_id <> ? LIMIT 1"
);
$check->bind_param('ssi', $username, $email, $user_id);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode(['success'=>false,'message'=>'Username or Email already exists']);
    exit;
}

/* ---- Update user ---- */
$stmt = $conn->prepare(
    "UPDATE users 
     SET username=?, full_name=?, email=?, role=?, phone=?, is_active=?
     WHERE user_id=?"
);
$stmt->bind_param(
    'sssssii',
    $username,
    $full_name,
    $email,
    $role,
    $phone,
    $is_active,
    $user_id
);

if ($stmt->execute()) {

    /* Fetch updated row for JS table refresh */
    $fetch = $conn->prepare(
        "SELECT created_at, last_login FROM users WHERE user_id=?"
    );
    $fetch->bind_param('i', $user_id);
    $fetch->execute();
    $meta = $fetch->get_result()->fetch_assoc();

    $response['success'] = true;
    $response['user'] = [
        'user_id'    => $user_id,
        'username'   => $username,
        'full_name'  => $full_name,
        'email'      => $email,
        'role'       => $role,
        'phone'      => $phone,
        'is_active'  => $is_active,
        'created_at'=> $meta['created_at'],
        'last_login'=> $meta['last_login']
    ];

    log_action(
        $_SESSION['user_id'],
        'Edit User',
        'users',
        $user_id,
        "Edited user $username"
    );

} else {
    $response['message'] = 'Failed to update user';
}

echo json_encode($response);
