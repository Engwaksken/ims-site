<?php
declare(strict_types=1);

function redirect_procurement_categories(): never
{
    header('Location: procurement_categories');
    exit;
}

function procurement_category_flash(string $type, string $message): void
{
    $_SESSION[$type] = $message;
}

function handleProcurementCategoryActions(mysqli $conn): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        match ($action) {
            'add_category'    => addProcurementCategory($conn),
            'update_category' => updateProcurementCategory($conn),
            'delete_category' => deleteProcurementCategory($conn),
            default           => redirect_procurement_categories(),
        };
    } catch (Throwable $e) {
        procurement_category_flash('error', $e->getMessage());
        redirect_procurement_categories();
    }
}

function addProcurementCategory(mysqli $conn): never
{
    $category_name = trim((string)($_POST['category_name'] ?? ''));
    $description   = trim((string)($_POST['description'] ?? ''));

    if ($category_name === '') {
        procurement_category_flash('error', 'Category name is required.');
        redirect_procurement_categories();
    }

    if (procurementCategoryNameExists($conn, $category_name)) {
        procurement_category_flash('error', 'This category already exists.');
        redirect_procurement_categories();
    }

    $stmt = $conn->prepare("
        INSERT INTO procurement_categories (category_name, description)
        VALUES (?, ?)
    ");

    if (!$stmt) {
        procurement_category_flash('error', 'Failed to prepare category insert: ' . $conn->error);
        redirect_procurement_categories();
    }

    $stmt->bind_param("ss", $category_name, $description);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    procurement_category_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Category added successfully.' : 'Failed to add category. ' . $err
    );

    redirect_procurement_categories();
}

function updateProcurementCategory(mysqli $conn): never
{
    $category_id   = (int)($_POST['category_id'] ?? 0);
    $category_name = trim((string)($_POST['category_name'] ?? ''));
    $description   = trim((string)($_POST['description'] ?? ''));

    if ($category_id <= 0) {
        procurement_category_flash('error', 'Invalid category selected.');
        redirect_procurement_categories();
    }

    if ($category_name === '') {
        procurement_category_flash('error', 'Category name is required.');
        redirect_procurement_categories();
    }

    if (!procurementCategoryExistsById($conn, $category_id)) {
        procurement_category_flash('error', 'Category not found.');
        redirect_procurement_categories();
    }

    if (procurementCategoryNameUsedByAnother($conn, $category_name, $category_id)) {
        procurement_category_flash('error', 'Another category already uses this name.');
        redirect_procurement_categories();
    }

    $stmt = $conn->prepare("
        UPDATE procurement_categories
        SET category_name = ?,
            description = ?
        WHERE category_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        procurement_category_flash('error', 'Failed to prepare category update: ' . $conn->error);
        redirect_procurement_categories();
    }

    $stmt->bind_param("ssi", $category_name, $description, $category_id);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    procurement_category_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Category updated successfully.' : 'Failed to update category. ' . $err
    );

    redirect_procurement_categories();
}

function deleteProcurementCategory(mysqli $conn): never
{
    $category_id = (int)($_POST['category_id'] ?? 0);

    if ($category_id <= 0) {
        procurement_category_flash('error', 'Invalid category selected.');
        redirect_procurement_categories();
    }

    if (!procurementCategoryExistsById($conn, $category_id)) {
        procurement_category_flash('error', 'Category not found.');
        redirect_procurement_categories();
    }

    if (procurementCategoryIsUsed($conn, $category_id)) {
        procurement_category_flash('error', 'Cannot delete category because it is used by procurement requests.');
        redirect_procurement_categories();
    }

    $stmt = $conn->prepare("
        DELETE FROM procurement_categories
        WHERE category_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        procurement_category_flash('error', 'Failed to prepare category delete: ' . $conn->error);
        redirect_procurement_categories();
    }

    $stmt->bind_param("i", $category_id);
    $ok  = $stmt->execute();
    $err = $stmt->error;
    $stmt->close();

    procurement_category_flash(
        $ok ? 'success' : 'error',
        $ok ? 'Category deleted successfully.' : 'Failed to delete category. ' . $err
    );

    redirect_procurement_categories();
}

function getProcurementCategoriesWithCounts(mysqli $conn): array
{
    $categories = [];

    $res = $conn->query("
        SELECT 
            c.category_id,
            c.category_name,
            c.description,
            c.created_at,
            COUNT(pr.procurement_id) AS request_count
        FROM procurement_categories c
        LEFT JOIN procurement_requests pr ON pr.category_id = c.category_id
        GROUP BY c.category_id, c.category_name, c.description, c.created_at
        ORDER BY c.category_name ASC
    ");

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $categories[] = $row;
        }
    }

    return $categories;
}

function procurementCategoryExistsById(mysqli $conn, int $category_id): bool
{
    $stmt = $conn->prepare("
        SELECT category_id
        FROM procurement_categories
        WHERE category_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function procurementCategoryNameExists(mysqli $conn, string $category_name): bool
{
    $stmt = $conn->prepare("
        SELECT category_id
        FROM procurement_categories
        WHERE LOWER(category_name) = LOWER(?)
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $category_name);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function procurementCategoryNameUsedByAnother(mysqli $conn, string $category_name, int $category_id): bool
{
    $stmt = $conn->prepare("
        SELECT category_id
        FROM procurement_categories
        WHERE LOWER(category_name) = LOWER(?)
          AND category_id <> ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("si", $category_name, $category_id);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function procurementCategoryIsUsed(mysqli $conn, int $category_id): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM procurement_requests
        WHERE category_id = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0) > 0;
}