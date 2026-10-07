
<div class="modal fade" id="createSurveyModal" tabindex="-1" aria-labelledby="createSurveyModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
    <form method="POST" id="createSurveyForm" class="modal-content">
     <input type="hidden" name="action" value="create_survey">

            <div class="modal-header">
                <h5 class="modal-title" id="createSurveyModalLabel">Create Survey</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>


            <div class="modal-body">
                <div class="row g-3">

                    <!-- Project -->
                    <div class="col-md-6">
                        <label class="form-label">Project (optional)</label>
                        <select name="project_id" class="form-select">
                            <option value="">Select project</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['project_id'] ?>"><?= htmlspecialchars($project['project_name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Program -->
                    <div class="col-md-6">
                        <label class="form-label">Program (optional)</label>
                        <select name="program_id" class="form-select">
                            <option value="">Select program</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= (int)$program['id'] ?>"><?= htmlspecialchars($program['program_name'], ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Survey Name</label>
                        <input type="text" name="survey_name" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Survey Type</label>
                        <select name="survey_type" class="form-select" required>
                            <option value="">Select type</option>
                            <option value="baseline">Baseline</option>
                            <option value="midline">Midline</option>
                            <option value="endline">Endline</option>
                            <option value="feedback">Feedback</option>
                            <option value="assessment">Assessment</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Survey Date</label>
                        <input type="date" name="survey_date" class="form-control" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Tool Used</label>
                        <select name="tool_used" id="tool_used" class="form-select" required>
                            <option value="">Select tool</option>
                            <option value="google_forms">Google Forms</option>
                            <option value="survey_cto">SurveyCTO</option>
                            <option value="kobo">KoBoToolbox</option>
                            <option value="microsoft_forms">Microsoft Forms</option>
                            <option value="embedded">Embedded Survey</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Respondents Count</label>
                        <input type="number" name="respondents_count" class="form-control" min="0" value="0">
                    </div>

                    <div class="col-md-6" id="filePathWrapper">
                        <label class="form-label">Survey Link / File Path</label>
                        <input type="text" name="file_path" id="file_path" class="form-control" placeholder="https://... or file path">
                        <small class="text-muted">For embedded surveys, this will be auto-generated.</small>
                    </div>

                    <div class="col-12">
                        <label class="form-label">Summary</label>
                        <textarea name="summary" class="form-control" rows="4" placeholder="Short description or summary of the survey"></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary">Save Survey</button>
            </div>
        </form>
    </div>
</div>