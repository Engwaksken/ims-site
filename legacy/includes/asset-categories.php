<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function redirect_categories(): never
{
    header('Location: asset_categories');
    exit;
}

function category_flash(string $type, string $message): void
{
    $_SESSION[$type] = $message;
}

function fail_prepare(mysqli $conn, string $context): never
{
    category_flash('error', $context . ': ' . $conn->error);
    redirect_categories();
}

function handleCategoryPostActions(mysqli $conn): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'add_category') {
            addAssetCategory($conn);
        } elseif ($action === 'update_category') {
            updateAssetCategory($conn);
        } elseif ($action === 'delete_category') {
            deleteAssetCategory($conn);
        } else {
            category_flash('error', 'Invalid action selected.');
            redirect_categories();
        }
    } catch (Throwable $e) {
        category_flash('error', $e->getMessage());
        redirect_categories();
    }
}

function addAssetCategory(mysqli $conn): never
{
    $category_name = trim((string)($_POST['category_name'] ?? ''));
    $description   = trim((string)($_POST['description'] ?? ''));

    if ($category_name === '') {
        category_flash('error', 'Category name is required.');
        redirect_categories();
    }

    if (assetCategoryExistsByName($conn, $category_name)) {
        category_flash('error', 'This category already exists.');
        redirect_categories();
    }

    $stmt = $conn->prepare("
        INSERT INTO asset_categories (category_name, description)
        VALUES (?, ?)
    ");

    if (!$stmt) {
        fail_prepare($conn, 'Failed to prepare category insert');
    }

    $stmt->bind_param('ss', $category_name, $description);

    if ($stmt->execute()) {
        category_flash('success', 'Category added successfully.');
    } else {
        category_flash('error', 'Failed to add category: ' . $stmt->error);
    }

    $stmt->close();
    redirect_categories();
}

function updateAssetCategory(mysqli $conn): never
{
    $category_id   = (int)($_POST['category_id'] ?? 0);
    $category_name = trim((string)($_POST['category_name'] ?? ''));
    $description   = trim((string)($_POST['description'] ?? ''));

    if ($category_id <= 0) {
        category_flash('error', 'Invalid category selected.');
        redirect_categories();
    }

    if ($category_name === '') {
        category_flash('error', 'Category name is required.');
        redirect_categories();
    }

    if (!assetCategoryExistsById($conn, $category_id)) {
        category_flash('error', 'Category not found.');
        redirect_categories();
    }

    if (assetCategoryNameUsedByAnother($conn, $category_name, $category_id)) {
        category_flash('error', 'Another category already uses this name.');
        redirect_categories();
    }

    $stmt = $conn->prepare("
        UPDATE asset_categories
        SET category_name = ?,
            description = ?
        WHERE category_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        fail_prepare($conn, 'Failed to prepare category update');
    }

    $stmt->bind_param('ssi', $category_name, $description, $category_id);

    if ($stmt->execute()) {
        category_flash('success', 'Category updated successfully.');
    } else {
        category_flash('error', 'Failed to update category: ' . $stmt->error);
    }

    $stmt->close();
    redirect_categories();
}

function deleteAssetCategory(mysqli $conn): never
{
    $category_id = (int)($_POST['category_id'] ?? 0);

    if ($category_id <= 0) {
        category_flash('error', 'Invalid category selected.');
        redirect_categories();
    }

    if (!assetCategoryExistsById($conn, $category_id)) {
        category_flash('error', 'Category not found.');
        redirect_categories();
    }

    if (assetCategoryIsUsed($conn, $category_id)) {
        category_flash('error', 'Cannot delete category because it is already used by assets or asset requests.');
        redirect_categories();
    }

    $stmt = $conn->prepare("
        DELETE FROM asset_categories
        WHERE category_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        fail_prepare($conn, 'Failed to prepare category delete');
    }

    $stmt->bind_param('i', $category_id);

    if ($stmt->execute()) {
        category_flash('success', 'Category deleted successfully.');
    } else {
        category_flash('error', 'Failed to delete category: ' . $stmt->error);
    }

    $stmt->close();
    redirect_categories();
}

function assetCategoryExistsById(mysqli $conn, int $category_id): bool
{
    $stmt = $conn->prepare("
        SELECT category_id
        FROM asset_categories
        WHERE category_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $category_id);
    $stmt->execute();

    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function assetCategoryExistsByName(mysqli $conn, string $category_name): bool
{
    $stmt = $conn->prepare("
        SELECT category_id
        FROM asset_categories
        WHERE LOWER(category_name) = LOWER(?)
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $category_name);
    $stmt->execute();

    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function assetCategoryNameUsedByAnother(mysqli $conn, string $category_name, int $category_id): bool
{
    $stmt = $conn->prepare("
        SELECT category_id
        FROM asset_categories
        WHERE LOWER(category_name) = LOWER(?)
          AND category_id <> ?
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $category_name, $category_id);
    $stmt->execute();

    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $exists;
}

function assetCategoryIsUsed(mysqli $conn, int $category_id): bool
{
    $stmt = $conn->prepare("
        SELECT 
            (
                SELECT COUNT(*)
                FROM asset_requests
                WHERE category_id = ?
            ) AS request_count,
            (
                SELECT COUNT(*)
                FROM assets
                WHERE category_id = ?
            ) AS asset_count
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $category_id, $category_id);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return ((int)($row['request_count'] ?? 0) > 0)
        || ((int)($row['asset_count'] ?? 0) > 0);
}