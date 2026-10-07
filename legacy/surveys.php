<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/process-surveys.php';

$page_title = 'Surveys';

/*
|--------------------------------------------------------------------------
| HTML escape helper
|--------------------------------------------------------------------------
*/

if (!function_exists('survey_h')) {
    function survey_h(mixed $value): string
    {
        return htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
?>

<link rel="stylesheet" href="css/assets.css">
<link rel="stylesheet" href="css/survey.css">

<div class="surveys-wrap">

    <!-- Page Hero -->
    <div class="surveys-hero">
        <div class="surveys-hero-text">
            <h1>
                <i class="fas fa-poll"></i>
                Surveys
            </h1>

            <p>
                Build, manage and analyse all your project and programme
                surveys in one place.
            </p>
        </div>

        <div class="hero-actions">
            <button
                type="button"
                class="btn btn-primary"
                onclick="openModal('createSurveyModal')"
            >
                <i class="fas fa-plus"></i>
                Create Survey
            </button>
        </div>
    </div>

    <!-- Success Message -->
    <?php if (!empty($_SESSION['survey_success'])): ?>
        <div
            class="alert alert-success alert-dismissible"
            role="alert"
        >
            <span>
                <i class="fas fa-check-circle"></i>

                <?= survey_h($_SESSION['survey_success']) ?>
            </span>

            <button
                type="button"
                class="alert-dismiss-btn"
                onclick="this.closest('.alert').remove()"
                aria-label="Dismiss message"
            >
                &times;
            </button>
        </div>

        <?php unset($_SESSION['survey_success']); ?>
    <?php endif; ?>

    <!-- Error Message -->
    <?php if (!empty($_SESSION['survey_error'])): ?>
        <div
            class="alert alert-error alert-dismissible"
            role="alert"
        >
            <span>
                <i class="fas fa-exclamation-circle"></i>

                <?= survey_h($_SESSION['survey_error']) ?>
            </span>

            <button
                type="button"
                class="alert-dismiss-btn"
                onclick="this.closest('.alert').remove()"
                aria-label="Dismiss message"
            >
                &times;
            </button>
        </div>

        <?php unset($_SESSION['survey_error']); ?>
    <?php endif; ?>

    <?php if (empty($surveys)): ?>

        <!-- Empty State -->
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-poll"></i>
            </div>

            <h3>No surveys yet</h3>

            <p>
                Create your first survey and link it to a project or
                programme to begin collecting responses.
            </p>

            <button
                type="button"
                class="btn btn-primary"
                onclick="openModal('createSurveyModal')"
            >
                <i class="fas fa-plus"></i>
                Create Survey
            </button>
        </div>

    <?php else: ?>

        <!-- Surveys Table -->
        <div class="survey-table-panel">
            <div class="survey-table-header">
                <div>
                    <h2 class="survey-table-title">
                        All Surveys
                    </h2>

                    <p class="survey-table-subtitle">
                        Manage your available surveys and access their
                        questions and analytics.
                    </p>
                </div>

                <span class="survey-table-count">
                    <?= number_format(count($surveys)) ?>
                </span>
            </div>

            <div class="survey-table-responsive">
                <table class="survey-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Survey Name</th>
                            <th>Survey Type</th>
                            <th>Project</th>
                            <th>Programme</th>
                            <th class="survey-actions-heading">
                                Actions
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($surveys as $index => $survey): ?>
                            <?php
                            $surveyId = (int) (
                                $survey['survey_id'] ?? 0
                            );

                            $surveyName = trim(
                                (string) (
                                    $survey['survey_name'] ?? ''
                                )
                            );

                            $surveyType = trim(
                                (string) (
                                    $survey['survey_type'] ?? ''
                                )
                            );

                            $projectName = trim(
                                (string) (
                                    $survey['project_name'] ?? ''
                                )
                            );

                            $programmeName = trim(
                                (string) (
                                    $survey['program_name'] ?? ''
                                )
                            );

                            $selectedProjectId = (int) (
                                $survey['project_id'] ?? 0
                            );

                            $selectedProgrammeId = (int) (
                                $survey['program_id'] ?? 0
                            );
                            ?>

                            <tr>
                                <td class="survey-number">
                                    <?= number_format($index + 1) ?>
                                </td>

                                <td class="survey-name-cell">
                                    <span class="survey-name">
                                        <?= survey_h(
                                            $surveyName !== ''
                                                ? $surveyName
                                                : 'Untitled Survey'
                                        ) ?>
                                    </span>

                                    <span class="survey-id">
                                        ID: <?= $surveyId ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($surveyType !== ''): ?>
                                        <span class="survey-type-badge">
                                            <i class="fas fa-tag"></i>

                                            <?= survey_h($surveyType) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="survey-empty-value">
                                            Not specified
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($projectName !== ''): ?>
                                        <span class="survey-link-value">
                                            <i class="fas fa-folder-open"></i>

                                            <?= survey_h($projectName) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="survey-empty-value">
                                            Not linked
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($programmeName !== ''): ?>
                                        <span class="survey-link-value">
                                            <i class="fas fa-layer-group"></i>

                                            <?= survey_h($programmeName) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="survey-empty-value">
                                            Not linked
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="survey-actions">
                                        <a
                                            href="survey-builder.php?id=<?= $surveyId ?>"
                                            class="btn btn-soft btn-sm"
                                            title="Build survey"
                                        >
                                            <i class="fas fa-pencil-alt"></i>
                                            Build
                                        </a>

                                        <a
                                            href="survey-analytics.php?id=<?= $surveyId ?>"
                                            class="btn btn-dark btn-sm"
                                            title="View analytics"
                                        >
                                            <i class="fas fa-chart-bar"></i>
                                            Analytics
                                        </a>

                                        <button
                                            type="button"
                                            class="btn btn-gray btn-sm"
                                            onclick="openModal(
                                                'editSurveyModal<?= $surveyId ?>'
                                            )"
                                            title="Edit survey"
                                        >
                                            <i class="fas fa-edit"></i>
                                            Edit
                                        </button>

                                        <button
                                            type="button"
                                            class="btn btn-red btn-sm btn-icon"
                                            onclick="openModal(
                                                'deleteSurveyModal<?= $surveyId ?>'
                                            )"
                                            title="Delete survey"
                                            aria-label="Delete survey"
                                        >
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Edit Survey Modal -->
                            <div
                                class="modal"
                                id="editSurveyModal<?= $surveyId ?>"
                                role="dialog"
                                aria-modal="true"
                                aria-labelledby="editSurveyTitle<?= $surveyId ?>"
                            >
                                <div class="modal-card">
                                    <div class="modal-head">
                                        <h3 id="editSurveyTitle<?= $surveyId ?>">
                                            <i class="fas fa-edit"></i>
                                            Edit Survey
                                        </h3>

                                        <button
                                            type="button"
                                            class="modal-close"
                                            onclick="closeModal(
                                                'editSurveyModal<?= $surveyId ?>'
                                            )"
                                            aria-label="Close modal"
                                        >
                                            &times;
                                        </button>
                                    </div>

                                    <div class="modal-body">
                                        <form
                                            method="POST"
                                            action="includes/process-surveys.php"
                                        >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="edit_survey"
                                            >

                                            <input
                                                type="hidden"
                                                name="survey_id"
                                                value="<?= $surveyId ?>"
                                            >

                                            <div class="modal-section-label">
                                                Survey Details
                                            </div>

                                            <div class="form-grid">
                                                <div class="form-group full">
                                                    <label
                                                        class="form-label"
                                                        for="edit_name_<?= $surveyId ?>"
                                                    >
                                                        Survey Name
                                                    </label>

                                                    <input
                                                        type="text"
                                                        id="edit_name_<?= $surveyId ?>"
                                                        name="survey_name"
                                                        class="form-control"
                                                        value="<?= survey_h($surveyName) ?>"
                                                        required
                                                    >
                                                </div>

                                                <div class="form-group full">
                                                    <label
                                                        class="form-label"
                                                        for="edit_type_<?= $surveyId ?>"
                                                    >
                                                        Survey Type
                                                    </label>

                                                    <input
                                                        type="text"
                                                        id="edit_type_<?= $surveyId ?>"
                                                        name="survey_type"
                                                        class="form-control"
                                                        value="<?= survey_h($surveyType) ?>"
                                                        placeholder="For example: Baseline, Midterm or Endline"
                                                    >
                                                </div>
                                            </div>

                                            <div class="modal-section-label">
                                                Survey Linkage
                                            </div>

                                            <div class="form-grid">
                                                <div class="form-group">
                                                    <label
                                                        class="form-label"
                                                        for="edit_project_<?= $surveyId ?>"
                                                    >
                                                        Project
                                                    </label>

                                                    <select
                                                        id="edit_project_<?= $surveyId ?>"
                                                        name="project_id"
                                                        class="form-control"
                                                    >
                                                        <option value="">
                                                            None
                                                        </option>

                                                        <?php foreach ($projects as $project): ?>
                                                            <?php
                                                            $projectId = (int) (
                                                                $project['project_id']
                                                                ?? 0
                                                            );
                                                            ?>

                                                            <option
                                                                value="<?= $projectId ?>"
                                                                <?= $projectId === $selectedProjectId
                                                                    ? 'selected'
                                                                    : '' ?>
                                                            >
                                                                <?= survey_h(
                                                                    $project['project_name']
                                                                    ?? ''
                                                                ) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="form-group">
                                                    <label
                                                        class="form-label"
                                                        for="edit_programme_<?= $surveyId ?>"
                                                    >
                                                        Programme
                                                    </label>

                                                    <select
                                                        id="edit_programme_<?= $surveyId ?>"
                                                        name="program_id"
                                                        class="form-control"
                                                    >
                                                        <option value="">
                                                            None
                                                        </option>

                                                        <?php foreach ($programs as $program): ?>
                                                            <?php
                                                            $programId = (int) (
                                                                $program['id']
                                                                ?? 0
                                                            );
                                                            ?>

                                                            <option
                                                                value="<?= $programId ?>"
                                                                <?= $programId === $selectedProgrammeId
                                                                    ? 'selected'
                                                                    : '' ?>
                                                            >
                                                                <?= survey_h(
                                                                    $program['program_name']
                                                                    ?? ''
                                                                ) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="modal-actions">
                                                <button
                                                    type="button"
                                                    class="btn btn-gray"
                                                    onclick="closeModal(
                                                        'editSurveyModal<?= $surveyId ?>'
                                                    )"
                                                >
                                                    Cancel
                                                </button>

                                                <button
                                                    type="submit"
                                                    class="btn btn-primary"
                                                >
                                                    <i class="fas fa-save"></i>
                                                    Save Changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Delete Survey Modal -->
                            <div
                                class="modal"
                                id="deleteSurveyModal<?= $surveyId ?>"
                                role="dialog"
                                aria-modal="true"
                                aria-labelledby="deleteSurveyTitle<?= $surveyId ?>"
                            >
                                <div class="modal-card modal-sm">
                                    <div class="modal-head">
                                        <h3 id="deleteSurveyTitle<?= $surveyId ?>">
                                            <i class="fas fa-trash"></i>
                                            Delete Survey
                                        </h3>

                                        <button
                                            type="button"
                                            class="modal-close"
                                            onclick="closeModal(
                                                'deleteSurveyModal<?= $surveyId ?>'
                                            )"
                                            aria-label="Close modal"
                                        >
                                            &times;
                                        </button>
                                    </div>

                                    <div class="modal-body">
                                        <form
                                            method="POST"
                                            action="includes/process-surveys.php"
                                        >
                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete_survey"
                                            >

                                            <input
                                                type="hidden"
                                                name="survey_id"
                                                value="<?= $surveyId ?>"
                                            >

                                            <div class="danger-zone">
                                                <div class="danger-zone-icon">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </div>

                                                <p>
                                                    You are about to permanently
                                                    delete

                                                    <strong>
                                                        “<?= survey_h($surveyName) ?>”
                                                    </strong>.

                                                    All related questions and
                                                    responses will also be
                                                    removed. This action cannot
                                                    be undone.
                                                </p>
                                            </div>

                                            <div class="modal-actions">
                                                <button
                                                    type="button"
                                                    class="btn btn-gray"
                                                    onclick="closeModal(
                                                        'deleteSurveyModal<?= $surveyId ?>'
                                                    )"
                                                >
                                                    Cancel
                                                </button>

                                                <button
                                                    type="submit"
                                                    class="btn btn-red"
                                                >
                                                    <i class="fas fa-trash"></i>
                                                    Yes, Delete
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>

<!-- Create Survey Modal -->
<?php include __DIR__ . '/partials/create-survey-modal.php'; ?>

<script>
function openModal(id) {
    const modal = document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.add('show');
    document.body.style.overflow = 'hidden';

    const focusableElement = modal.querySelector(
        'input:not([type="hidden"]), select, textarea, button'
    );

    if (focusableElement) {
        window.setTimeout(function () {
            focusableElement.focus();
        }, 100);
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);

    if (!modal) {
        return;
    }

    modal.classList.remove('show');

    if (!document.querySelector('.modal.show')) {
        document.body.style.overflow = '';
    }
}


document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal(modal.id);
        }
    });
});



document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') {
        return;
    }

    document
        .querySelectorAll('.modal.show')
        .forEach(function (modal) {
            closeModal(modal.id);
        });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>