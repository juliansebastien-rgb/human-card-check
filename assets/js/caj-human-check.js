document.addEventListener('change', function (event) {
    var target = event.target;

    if (!target.matches('.human-card-check__answer input[type="radio"]')) {
        return;
    }

    var answers = target.closest('.human-card-check__answers');
    if (!answers) {
        return;
    }

    answers.querySelectorAll('.human-card-check__answer').forEach(function (label) {
        label.classList.remove('is-selected');
    });

    var selected = target.closest('.human-card-check__answer');
    if (selected) {
        selected.classList.add('is-selected');
    }
});
