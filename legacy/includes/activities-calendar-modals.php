<?php
declare(strict_types=1);

?>

<!-- ADD ACTIVITY MODAL -->
<div class="modal" id="addActivityModal">
    <div class="modal-dialog">
        <form method="POST" action="actions/activities-calendar-actions.php">
            <input type="hidden" name="add_activity" value="1">

            <div class="modal-header">
                <h3><i class="fas fa-calendar-plus"></i> Add Activity</h3>
                <button type="button" class="modal-close" onclick="closeModal('addActivityModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Entity Type *</label>
                        <select name="entity_type" class="form-control" required onchange="acToggleEntity(this, 'add')">
                            <option value="Project">Project</option>
                            <?php if (!empty($programs)): ?>
                                <option value="Program">Program</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group" id="add_projectField">
                        <label class="form-label">Project *</label>
                        <select name="project_entity_id" class="form-control">
                            <option value="">Select a project...</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo (int)$project['project_id']; ?>">
                                    <?php echo h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!empty($programs)): ?>
                    <div class="form-group" id="add_programField" style="display:none;">
                        <label class="form-label">Program *</label>
                        <select name="program_entity_id" class="form-control">
                            <option value="">Select a program...</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo (int)$program['id']; ?>">
                                    <?php echo h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Calendar Year</label>
                        <input type="number" name="calendar_year" class="form-control" value="<?php echo (int)date('Y'); ?>">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Activity / Event *</label>
                        <input type="text" name="activity_name" class="form-control" required placeholder="e.g. Founders Day Cafe Series">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <?php foreach ($allowed_statuses as $status): ?>
                                <option value="<?php echo h($status); ?>" <?php echo $status === 'Planned' ? 'selected' : ''; ?>>
                                    <?php echo h($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Proposed Format</label>
                        <textarea name="proposed_format" class="form-control" rows="2" placeholder="e.g. 1-day in-person workshop with panel discussions"></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Resources Required</label>
                        <textarea name="resources_required" class="form-control" rows="2" placeholder="e.g. Venue, AV equipment, catering, facilitator fees"></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Purpose</label>
                        <textarea name="purpose" class="form-control" rows="2" placeholder="What is this activity meant to achieve?"></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Intended Outcomes</label>
                        <textarea name="intended_outcomes" class="form-control" rows="2" placeholder="e.g. 12 ventures onboarded, 80% attendance..."></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Comments / M&amp;E Notes</label>
                        <textarea name="comments" class="form-control" rows="2" placeholder="Optional progress note"></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-gray" onclick="closeModal('addActivityModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Activity</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT ACTIVITY MODAL -->
<div class="modal" id="editActivityModal">
    <div class="modal-dialog">
        <form method="POST" action="actions/activities-calendar-actions.php">
            <input type="hidden" name="edit_activity" value="1">
            <input type="hidden" name="activity_id" value="<?php echo (int)($edit_activity['activity_id'] ?? 0); ?>">

            <div class="modal-header">
                <h3><i class="fas fa-pen-to-square"></i> Edit Activity</h3>
                <button type="button" class="modal-close" onclick="closeModal('editActivityModal')">&times;</button>
            </div>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Entity Type *</label>
                        <select name="entity_type" class="form-control" required onchange="acToggleEntity(this, 'edit')">
                            <option value="Project" <?php echo ($edit_activity['entity_type'] ?? '') === 'Project' ? 'selected' : ''; ?>>Project</option>
                            <?php if (!empty($programs)): ?>
                                <option value="Program" <?php echo ($edit_activity['entity_type'] ?? '') === 'Program' ? 'selected' : ''; ?>>Program</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="form-group" id="edit_projectField" style="display:<?php echo ($edit_activity['entity_type'] ?? 'Project') === 'Project' ? 'flex' : 'none'; ?>;">
                        <label class="form-label">Project *</label>
                        <select name="project_entity_id" class="form-control">
                            <option value="">Select a project...</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo (int)$project['project_id']; ?>"
                                    <?php echo (($edit_activity['entity_type'] ?? '') === 'Project' && (int)($edit_activity['entity_id'] ?? 0) === (int)$project['project_id']) ? 'selected' : ''; ?>>
                                    <?php echo h(($project['project_code'] ?? '') . ' - ' . ($project['project_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!empty($programs)): ?>
                    <div class="form-group" id="edit_programField" style="display:<?php echo ($edit_activity['entity_type'] ?? '') === 'Program' ? 'flex' : 'none'; ?>;">
                        <label class="form-label">Program *</label>
                        <select name="program_entity_id" class="form-control">
                            <option value="">Select a program...</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo (int)$program['id']; ?>"
                                    <?php echo (($edit_activity['entity_type'] ?? '') === 'Program' && (int)($edit_activity['entity_id'] ?? 0) === (int)$program['id']) ? 'selected' : ''; ?>>
                                    <?php echo h(($program['program_code'] ?? '') . ' - ' . ($program['program_name'] ?? '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="form-label">Calendar Year</label>
                        <input type="number" name="calendar_year" class="form-control" value="<?php echo h($edit_activity['calendar_year'] ?? date('Y')); ?>">
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Activity / Event *</label>
                        <input type="text" name="activity_name" class="form-control" required value="<?php echo h($edit_activity['activity_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo h($edit_activity['start_date'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo h($edit_activity['end_date'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <?php foreach ($allowed_statuses as $status): ?>
                                <option value="<?php echo h($status); ?>" <?php echo ($edit_activity['status'] ?? '') === $status ? 'selected' : ''; ?>>
                                    <?php echo h($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Proposed Format</label>
                        <textarea name="proposed_format" class="form-control" rows="2"><?php echo h($edit_activity['proposed_format'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Resources Required</label>
                        <textarea name="resources_required" class="form-control" rows="2"><?php echo h($edit_activity['resources_required'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Purpose</label>
                        <textarea name="purpose" class="form-control" rows="2"><?php echo h($edit_activity['purpose'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Intended Outcomes</label>
                        <textarea name="intended_outcomes" class="form-control" rows="2"><?php echo h($edit_activity['intended_outcomes'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label class="form-label">Comments / M&amp;E Notes</label>
                        <textarea name="comments" class="form-control" rows="2"><?php echo h($edit_activity['comments'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-gray" onclick="closeModal('editActivityModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Activity</button>
            </div>
        </form>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal" id="deleteActivityModal">
    <div class="modal-dialog modal-sm">
        <form method="POST" action="actions/activities-calendar-actions.php">
            <input type="hidden" name="delete_activity" value="1">
            <input type="hidden" name="activity_id" id="deleteActivityId" value="">

            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i> Delete Activity</h3>
                <button type="button" class="modal-close" onclick="closeModal('deleteActivityModal')">&times;</button>
            </div>

            <div class="modal-body">
                <p>Are you sure you want to delete "<strong id="deleteActivityName"></strong>"? This cannot be undone.</p>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-gray" onclick="closeModal('deleteActivityModal')">Cancel</button>
                <button type="submit" class="btn btn-red"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    window.acToggleEntity = function (selectEl, prefix) {
        const type = selectEl.value;
        const projectField = document.getElementById(prefix + '_projectField');
        const programField = document.getElementById(prefix + '_programField');

        if (type === 'Program') {
            if (projectField) projectField.style.display = 'none';
            if (programField) programField.style.display = 'flex';
        } else {
            if (projectField) projectField.style.display = 'flex';
            if (programField) programField.style.display = 'none';
        }
    };
})();
</script>