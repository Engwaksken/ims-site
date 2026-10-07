<!-- Create Survey Modal: app modal pattern (.modal > .modal-card, toggled by
     openModal()/closeModal() in main.js). Do not use Bootstrap markup here;
     Bootstrap is not loaded and its .modal-dialog/.modal-content classes
     collide with the app's own modal styles. -->
<div
    class="modal"
    id="createSurveyModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="createSurveyModalLabel"
>
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="createSurveyModalLabel">
                <i class="fas fa-plus-circle" aria-hidden="true"></i>
                Create Survey
            </h3>
            <button
                type="button"
                class="modal-close"
                onclick="closeModal('createSurveyModal')"
                aria-label="Close modal"
            >&times;</button>
        </div>

        <div class="modal-body">
            <form method="POST" id="createSurveyForm">
                <input type="hidden" name="action" value="create_survey">

                <div class="modal-section-label">Survey Linkage</div>
                <div class="form-grid">
                    <!-- Project -->
                    <div class="form-group">
                        <label class="form-label" for="create_project_id">Project (optional)</label>
                        <select name="project_id" id="create_project_id" class="form-control">
                            <option value="">Select project</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>"><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Program -->
                    <div class="form-group">
                        <label class="form-label" for="create_program_id">Program (optional)</label>
                        <select name="program_id" id="create_program_id" class="form-control">
                            <option value="">Select program</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>"><?= htmlspecialchars($program['program_name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="modal-section-label">Survey Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label" for="create_survey_name">Survey Name</label>
                        <input type="text" name="survey_name" id="create_survey_name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="create_survey_type">Survey Type</label>
                        <select name="survey_type" id="create_survey_type" class="form-control" required>
                            <option value="">Select type</option>
                            <option value="baseline">Baseline</option>
                            <option value="midline">Midline</option>
                            <option value="endline">Endline</option>
                            <option value="feedback">Feedback</option>
                            <option value="assessment">Assessment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="create_survey_date">Survey Date</label>
                        <input type="date" name="survey_date" id="create_survey_date" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="tool_used">Tool Used</label>
                        <select name="tool_used" id="tool_used" class="form-control" required>
                            <option value="">Select tool</option>
                            <option value="google_forms">Google Forms</option>
                            <option value="survey_cto">SurveyCTO</option>
                            <option value="kobo">KoBoToolbox</option>
                            <option value="microsoft_forms">Microsoft Forms</option>
                            <option value="embedded">Embedded Survey</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="create_respondents_count">Respondents Count</label>
                        <input type="number" name="respondents_count" id="create_respondents_count" class="form-control" min="0" value="0">
                    </div>

                    <div class="form-group" id="filePathWrapper">
                        <label class="form-label" for="file_path">Survey Link / File Path</label>
                        <input type="text" name="file_path" id="file_path" class="form-control" placeholder="https://... or file path" aria-describedby="file_path_hint">
                        <div class="form-hint" id="file_path_hint">For embedded surveys, this will be auto-generated.</div>
                    </div>

                    <div class="form-group full">
                        <label class="form-label" for="create_summary">Summary</label>
                        <textarea name="summary" id="create_summary" class="form-control" rows="4" placeholder="Short description or summary of the survey"></textarea>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-gray" onclick="closeModal('createSurveyModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save" aria-hidden="true"></i>
                        Save Survey
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>