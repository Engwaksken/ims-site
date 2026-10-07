<?php
/*
 * Edit KPI
 *
 * The kpis table uses the employee-KPI schema (fiscal_year, category_id,
 * quarterly targets). The old form here was written for an older
 * project-KPI schema (project_id, owner, kpi_name) whose columns no longer
 * exist, so every edit failed. Editing is now handled by the create-kpi
 * form in edit mode, which saves through kpi-process.php (action=update).
 */
$kpi_edit_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($kpi_edit_id > 0) {
    require __DIR__ . '/create-kpi.php';
    exit();
}

header('Location: my-kpis');
exit();

