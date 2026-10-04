/*
 * Small enhancements for every page. The application works without
 * JavaScript; this file only adds conveniences.
 */
(function () {
    'use strict';

    // PIN fields accept digits only (at most 4).
    document.querySelectorAll('input[data-pin]').forEach(function (input) {
        input.addEventListener('input', function () {
            var clean = input.value.replace(/\D/g, '').slice(0, 4);
            if (clean !== input.value) {
                input.value = clean;
            }
        });
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var button = event.submitter;

        // Ask for confirmation when the button or the form has data-confirm.
        var message = (button && button.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        // Forms marked data-once can only be sent once (no double submissions).
        if (form.hasAttribute('data-once')) {
            if (form.getAttribute('data-sent') === '1') {
                event.preventDefault();
                return;
            }
            form.setAttribute('data-sent', '1');
            // Disable the buttons after the browser has collected the form data,
            // so the clicked button's name and value are still sent.
            window.setTimeout(function () {
                form.querySelectorAll('button[type="submit"]').forEach(function (b) {
                    b.disabled = true;
                });
            }, 0);
        }
    });

    // Filter dropdowns marked data-autosubmit apply as soon as they change.
    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () {
            if (select.form) {
                select.form.submit();
            }
        });
    });

    // Print buttons.
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });

    // Side menu on small screens.
    var toggle = document.querySelector('.menu-toggle');
    var sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        toggle.addEventListener('click', function () {
            var open = sidebar.classList.toggle('open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.textContent = open ? 'Close' : 'Menu';
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.textContent = 'Menu';
                toggle.focus();
            }
        });
    }

    // "Select all" checkbox for a group of checkboxes (data-check-all="name").
    document.querySelectorAll('input[data-check-all]').forEach(function (master) {
        master.addEventListener('change', function () {
            var selector = 'input[type="checkbox"][data-group="' + master.getAttribute('data-check-all') + '"]';
            document.querySelectorAll(selector).forEach(function (box) {
                box.checked = master.checked;
            });
        });
    });

    // Live character counter: <textarea maxlength="..." data-counter="id of the counter element">.
    document.querySelectorAll('textarea[data-counter]').forEach(function (area) {
        var counter = document.getElementById(area.getAttribute('data-counter'));
        var max = parseInt(area.getAttribute('maxlength'), 10);
        if (!counter || !max) {
            return;
        }
        var update = function () {
            counter.textContent = area.value.length + ' of ' + max + ' characters';
        };
        area.addEventListener('input', update);
        update();
    });
})();
