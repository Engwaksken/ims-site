<?php

$project = $project ?? []; 

function field_value($field, $default = '') {
    global $project;
    return htmlspecialchars($project[$field] ?? $default);
}

function selected($field, $value, $default = '') {
    global $project;
    return (($project[$field] ?? $default) == $value) ? 'selected' : '';
}
?>

<div class="form-row">
    <div class="form-group">
        <label for="project_name" class="required">Project Name</label>
        <input type="text" id="project_name" name="project_name" class="form-control" value="<?= field_value('project_name'); ?>" required>
    </div>
    <div class="form-group">
        <label for="project_code">Project Code</label>
        <input type="text" id="project_code" name="project_code" class="form-control" value="<?= field_value('project_code'); ?>" placeholder="Auto-generated if left empty">
    </div>
</div>

<div class="form-row">
    <div class="form-group">
        <label for="donor_id" class="required">Donor</label>
        <select id="donor_id" name="donor_id" class="form-control" required>
            <option value="">Select Donor</option>
            <?php foreach ($donors as $donor): ?>
                <option value="<?= $donor['donor_id']; ?>" <?= selected('donor_id', $donor['donor_id']); ?>>
                    <?= htmlspecialchars($donor['donor_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="value_chain">Value Chain/Sector</label>
        <input type="text" id="value_chain" name="value_chain" class="form-control" value="<?= field_value('value_chain'); ?>" placeholder="e.g., Agriculture, Technology">
    </div>
</div>

<div class="form-row">
    <div class="form-group">
        <label for="start_date" class="required">Start Date</label>
        <input type="date" id="start_date" name="start_date" class="form-control" value="<?= field_value('start_date'); ?>" required>
    </div>
    <div class="form-group">
        <label for="end_date" class="required">End Date</label>
        <input type="date" id="end_date" name="end_date" class="form-control" value="<?= field_value('end_date'); ?>" required>
    </div>
</div>

<div class="form-row">
    <div class="form-group">
        <label for="budget" class="required">Budget</label>
        <input type="number" id="budget" name="budget" class="form-control" step="0.01" value="<?= field_value('budget'); ?>" required>
    </div>
    <div class="form-group">
        <label for="currency">Currency</label>
        <select id="currency" name="currency" class="form-control">
            <?php 
            $currencies = ['UGX', 'USD', 'EUR', 'GBP'];
            foreach ($currencies as $cur): ?>
                <option value="<?= $cur; ?>" <?= selected('currency', $cur, 'UGX'); ?>><?= $cur; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="status" class="required">Status</label>
        <select id="status" name="status" class="form-control" required>
            <?php
            $statuses = ['Pending', 'Ongoing', 'Completed', 'Cancelled'];
            foreach ($statuses as $status): ?>
                <option value="<?= $status; ?>" <?= selected('status', $status, 'Ongoing'); ?>><?= $status; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="form-group">
    <label for="geographic_scope">Geographic Scope</label>
    <input type="text" id="geographic_scope" name="geographic_scope" class="form-control" value="<?= field_value('geographic_scope'); ?>" placeholder="Districts, regions covered">
</div>

<div class="form-group">
    <label for="objectives">Project Objectives</label>
    <textarea id="objectives" name="objectives" class="form-control" rows="4"><?= field_value('objectives'); ?></textarea>
</div>

<div class="form-group">
    <label for="description">Project Description</label>
    <textarea id="description" name="description" class="form-control" rows="4"><?= field_value('description'); ?></textarea>
</div>
