// Create Survey modal (legacy/partials/create-survey-modal.php).
//
// The modal itself is opened/closed by openModal()/closeModal() in main.js
// (backdrop click and Escape are handled there too). This file only adds the
// "Tool Used" behaviour: embedded surveys get an auto-generated link, so the
// Survey Link / File Path field is locked for them.
//
// The form submits normally (POST to surveys.php, handled by
// includes/process-surveys.php). The old Bootstrap/AJAX submit handler was
// removed: Bootstrap is not loaded in this app and the handler expected a JSON
// response the server never sends.
document.addEventListener('DOMContentLoaded', function () {
    const toolUsed = document.getElementById('tool_used');
    const filePathWrapper = document.getElementById('filePathWrapper');
    const filePathInput = document.getElementById('file_path');

    function toggleFilePath() {
        if (!toolUsed || !filePathWrapper || !filePathInput) return;

        if (toolUsed.value === 'embedded') {
            filePathWrapper.style.opacity = '0.6';
            filePathInput.value = '';
            filePathInput.readOnly = true;
            filePathInput.placeholder = 'Auto-generated for embedded survey';
        } else {
            filePathWrapper.style.opacity = '1';
            filePathInput.readOnly = false;
            filePathInput.placeholder = 'https://... or file path';
        }
    }

    if (toolUsed) {
        toolUsed.addEventListener('change', toggleFilePath);
        toggleFilePath();
    }

    const surveyForm = document.getElementById('createSurveyForm');
    if (surveyForm) {
        surveyForm.addEventListener('reset', function () {
            window.setTimeout(toggleFilePath, 0);
        });
    }
});