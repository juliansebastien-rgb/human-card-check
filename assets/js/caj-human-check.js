document.addEventListener('change', function (event) {
    var target = event.target;

    if (!target.matches('.caj-human-check__answer input[type="radio"]')) {
        return;
    }

    var answers = target.closest('.caj-human-check__answers');
    if (!answers) {
        return;
    }

    answers.querySelectorAll('.caj-human-check__answer').forEach(function (label) {
        label.classList.remove('is-selected');
    });

    var selected = target.closest('.caj-human-check__answer');
    if (selected) {
        selected.classList.add('is-selected');
    }
});
