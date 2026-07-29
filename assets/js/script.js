/**
 * Barangay Health Center Survey Management System
 * Shared client-side behavior.
 */

// Confirm before destructive actions (deactivate/delete)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(el.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // Toggle extra fields (e.g. choices) based on selected question type
    document.querySelectorAll('.question-type-select').forEach(function (select) {
        select.addEventListener('change', function () {
            const row = select.closest('.question-row');
            if (!row) return;
            const choicesBox = row.querySelector('.choices-box');
            if (choicesBox) {
                choicesBox.style.display = (select.value === 'multiple_choice') ? 'block' : 'none';
            }
        });
        select.dispatchEvent(new Event('change'));
    });

    // Dynamic add/remove of choice inputs when building a multiple-choice question
    document.querySelectorAll('.add-choice-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const box = document.querySelector(btn.dataset.target);
            const idx = box.querySelectorAll('input').length;
            const input = document.createElement('input');
            input.type = 'text';
            input.name = btn.dataset.name + '[]';
            input.placeholder = 'Choice ' + (idx + 1);
            box.appendChild(input);
        });
    });

    // Simple client-side required-field check for survey answer forms
    const surveyForm = document.getElementById('survey-answer-form');
    if (surveyForm) {
        surveyForm.addEventListener('submit', function (e) {
            let valid = true;
            surveyForm.querySelectorAll('[data-required-group]').forEach(function (group) {
                const name = group.dataset.requiredGroup;
                const checked = surveyForm.querySelector('input[name="' + name + '"]:checked');
                const textInput = surveyForm.querySelector('textarea[name="' + name + '"], input[type=text][name="' + name + '"]');
                if (!checked && textInput === null) {
                    valid = false;
                    group.classList.add('missing');
                } else {
                    group.classList.remove('missing');
                }
            });
            if (!valid) {
                e.preventDefault();
                alert('Please answer all required questions before submitting.');
            }
        });
    }
});
