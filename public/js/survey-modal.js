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

    // -----------------------------
    // AJAX SUBMISSION FOR CREATE SURVEY
    // -----------------------------
    const surveyForm = document.getElementById('createSurveyForm');
    if (surveyForm) {
        surveyForm.addEventListener('submit', function (e) {
            e.preventDefault();
            
            const formData = new FormData(surveyForm);
            const submitBtn = surveyForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            fetch('includes/process-surveys.php', {
                method: 'POST',
                body: formData,
            })
            .then(res => res.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Survey';

                if (data.success) {
                    // Close modal
                    const modalEl = document.getElementById('createSurveyModal');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    modal.hide();

                    // Reset form
                    surveyForm.reset();
                    toggleFilePath();

                    // Add new survey to the list
                    if (data.surveyHtml) {
                        const container = document.querySelector('.container.py-4');
                        container.insertAdjacentHTML('afterbegin', data.surveyHtml);
                    }

                    // Optional toast / alert
                    alert('Survey created successfully!');
                } else {
                    alert(data.error || 'Failed to create survey.');
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Survey';
                console.error(err);
                alert('An error occurred.');
            });
        });
    }
});