(function () {
    var form = document.querySelector('form[data-create-once]');
    if (!form) return;
    var submitting = false;
    form.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        if (submitting) {
            event.preventDefault();
            return;
        }
        submitting = true;
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            button.disabled = true;
            button.style.opacity = '0.6';
            if (button.tagName === 'BUTTON') button.textContent = 'Создание и запуск процессов…';
        });
    });
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) window.location.reload();
    });
}());
