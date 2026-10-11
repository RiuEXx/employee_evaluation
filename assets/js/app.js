/*
 * Small enhancements for every page. The application works without
 * JavaScript; this file only adds conveniences.
 */
(function () {
    'use strict';

    // Keeps all open tabs in step with the login. Logging in, logging out or switching account
    // in one tab gives the session a new form token, so forms left open in other tabs would be
    // refused ("The form has expired"). Each page names the logged-in user in
    // <body data-session="user-12" or "none"> and shares it through localStorage; when another
    // tab shares a different value, this tab loads its page again and the server sends it to
    // the right place (the dashboard after a login, the login page after a logout).
    (function () {
        var mine = document.body.getAttribute('data-session');
        if (mine === null) {
            return; // error pages do not take part
        }
        var KEY = 'eval-session';
        var shared = function () {
            try {
                return window.localStorage.getItem(KEY);
            } catch (e) {
                return null; // storage blocked: tabs simply are not kept in step
            }
        };
        var reload = function () {
            // Guard against a reload loop: at most 3 automatic reloads per 10 seconds.
            var now = Date.now();
            var recent = [];
            try {
                recent = JSON.parse(window.sessionStorage.getItem('eval-session-reloads') || '[]')
                    .filter(function (t) { return now - t < 10000; });
                if (recent.length >= 3) {
                    return;
                }
                recent.push(now);
                window.sessionStorage.setItem('eval-session-reloads', JSON.stringify(recent));
            } catch (e) { /* no sessionStorage: reload anyway */ }
            // A GET of the same address: never re-sends a form (no "Confirm resubmission").
            window.location.replace(window.location.href.split('#')[0]);
        };
        var checkShared = function () {
            var value = shared();
            if (value !== null && value !== mine) {
                reload();
            }
        };
        try {
            window.localStorage.setItem(KEY, mine);
        } catch (e) { /* see above */ }
        window.addEventListener('storage', function (event) {
            if (event.key === KEY && event.newValue !== null && event.newValue !== mine) {
                reload();
            }
        });
        // A tab restored from the back/forward cache, or brought back into view, checks again.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                checkShared();
            }
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                checkShared();
            }
        });
    })();

    // PIN fields accept digits only (at most 4).
    document.querySelectorAll('input[data-pin]').forEach(function (input) {
        input.addEventListener('input', function () {
            var clean = input.value.replace(/\D/g, '').slice(0, 4);
            if (clean !== input.value) {
                input.value = clean;
            }
        });
    });

    // Shows (or with an empty message, clears) an error under a field, styled like the
    // server's messages. The server checks every value again when the form is sent.
    // In a filter row the message goes on its own line under the row instead, named
    // after the field and marked data-for="<field id>", as the server writes it there.
    var showFieldError = function (input, message) {
        var field = input.closest('.field');
        if (!field) {
            return;
        }
        var inRow = field.parentElement.classList.contains('filters');
        var holder = inRow ? field.parentElement : field;
        var mine = inRow ? '[data-for="' + input.id + '"]' : '';
        var live = holder.querySelector('.js-field-error' + mine);
        if (!message) {
            if (live) {
                live.remove();
            }
            if (!holder.querySelector('.field-error' + mine)) {
                field.classList.remove('has-error');
            }
            return;
        }
        // The server's message for this field is replaced by the live one.
        holder.querySelectorAll('.field-error:not(.js-field-error)' + mine).forEach(function (old) {
            old.remove();
        });
        if (!live) {
            live = document.createElement('p');
            live.className = 'field-error js-field-error';
            live.setAttribute('aria-live', 'polite');
            if (inRow) {
                live.setAttribute('data-for', input.id);
            }
            holder.appendChild(live);
        }
        var label = field.querySelector('label');
        live.textContent = (inRow && label ? label.textContent.trim() + ': ' : '') + message;
        field.classList.add('has-error');
    };

    // Number fields: data-digits accepts only the digits 0 to 9 (no "e", sign, decimal point or
    // other characters); data-decimal also accepts one point with at most two decimals.
    // data-min and data-max give the allowed range.
    var cleanNumber = function (value, decimal) {
        if (!decimal) {
            return value.replace(/\D+/g, '');
        }
        var parts = value.replace(/[^\d.]+/g, '').split('.');
        return parts.length > 1 ? parts[0] + '.' + parts.slice(1).join('').slice(0, 2) : parts[0];
    };
    // data-message replaces the standard message; minlength sets the fewest digits.
    var numberProblem = function (input) {
        var decimal = input.hasAttribute('data-decimal');
        var min = input.getAttribute('data-min');
        var max = input.getAttribute('data-max');
        var value = parseFloat(input.value);
        if (input.value === '' || isNaN(value) || /\.$/.test(input.value)
            || (input.minLength > 0 && input.value.length < input.minLength)
            || (min !== null && value < parseFloat(min)) || (max !== null && value > parseFloat(max))) {
            return input.getAttribute('data-message') || (decimal
                ? 'Enter a number from ' + min + ' to ' + max + ', with at most 2 decimals.'
                : 'Enter a whole number from ' + min + ' to ' + max + '.');
        }
        return '';
    };
    document.querySelectorAll('input[data-digits], input[data-decimal]').forEach(function (input) {
        var decimal = input.hasAttribute('data-decimal');
        input.addEventListener('keydown', function (event) {
            if (event.ctrlKey || event.metaKey || event.altKey || event.key.length !== 1) {
                return; // shortcuts, arrows, Backspace, Tab, ...
            }
            var allowed = /\d/.test(event.key) || (decimal && event.key === '.' && input.value.indexOf('.') === -1);
            if (!allowed) {
                event.preventDefault();
            }
        });
        // Pasted or dropped text is cleaned too.
        input.addEventListener('input', function () {
            var clean = cleanNumber(input.value, decimal);
            if (clean !== input.value) {
                input.value = clean;
            }
            if (!numberProblem(input)) {
                showFieldError(input, '');
            }
        });
        input.addEventListener('change', function () {
            showFieldError(input, numberProblem(input));
        });
    });

    // Text fields take plain text only: emoji are removed as they are typed or pasted, with a
    // note under the field that stays until the field is left. The pattern matches
    // EMOJI_PATTERN in includes/functions.php, which checks every value again on the server.
    var EMOJI = /(?=\p{Extended_Pictographic})[^\u0000-⋿]|[\u{1F1E6}-\u{1F1FF}\u{1F3FB}-\u{1F3FF}️⃣‍\u{E0020}-\u{E007F}]/gu;
    document.querySelectorAll('input[type="text"]:not([data-digits]):not([data-decimal]):not([data-pin]), '
        + 'input[type="search"], input[type="email"], textarea').forEach(function (input) {
        input.addEventListener('blur', function () {
            if (input.getAttribute('data-emoji-note') === '1') {
                input.removeAttribute('data-emoji-note');
                showFieldError(input, '');
            }
        });
        input.addEventListener('input', function () {
            EMOJI.lastIndex = 0;
            if (!EMOJI.test(input.value)) {
                return;
            }
            var caret = input.selectionStart;
            var before = input.value.length;
            input.value = input.value.replace(EMOJI, '');
            if (caret !== null && input.type !== 'email') {
                var at = Math.max(0, caret - (before - input.value.length));
                input.setSelectionRange(at, at);
            }
            input.setAttribute('data-emoji-note', '1');
            showFieldError(input, 'Emoji are not allowed. Use plain text only.');
        });
    });

    // Letters-only fields (data-letters), e.g. a role's number tag: A to Z, upper case.
    document.querySelectorAll('input[data-letters]').forEach(function (input) {
        input.addEventListener('input', function () {
            var clean = input.value.toUpperCase().replace(/[^A-Z]+/g, '');
            if (clean !== input.value) {
                input.value = clean;
            }
        });
    });

    // A role picker with data-prefix-target shows the chosen role's number tag (its option's
    // data-prefix) in front of the employee number field.
    document.querySelectorAll('select[data-prefix-target]').forEach(function (select) {
        var target = document.getElementById(select.getAttribute('data-prefix-target'));
        if (!target) {
            return;
        }
        select.addEventListener('change', function () {
            var option = select.options[select.selectedIndex];
            target.textContent = option ? option.getAttribute('data-prefix') || '' : '';
        });
    });

    // Date fields with min and max: a date outside the range (for example a five- or
    // six-digit year, which browsers let you type) is flagged when the field is left.
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var dayNumber = function (value) { // "2026-10-11" -> 20261011, also for years longer than 4 digits
        var m = /^(\d+)-(\d{2})-(\d{2})$/.exec(value || '');
        return m ? parseInt(m[1], 10) * 10000 + parseInt(m[2], 10) * 100 + parseInt(m[3], 10) : NaN;
    };
    var prettyDate = function (value) { // "2026-10-11" -> "Oct 11, 2026", like the server's messages
        var m = /^(\d+)-(\d{2})-(\d{2})$/.exec(value || '');
        return m ? MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1] : value;
    };
    var dateProblem = function (input) {
        if (input.validity && input.validity.badInput) {
            return 'Enter a complete, valid date.';
        }
        if (input.value === '') {
            return '';
        }
        var day = dayNumber(input.value);
        if (isNaN(day) || (input.min && day < dayNumber(input.min)) || (input.max && day > dayNumber(input.max))) {
            return 'Choose a date from ' + prettyDate(input.min) + ' to ' + prettyDate(input.max) + '.';
        }
        return '';
    };
    document.querySelectorAll('input[type="date"][min], input[type="date"][max]').forEach(function (input) {
        input.addEventListener('blur', function () {
            showFieldError(input, dateProblem(input));
        });
        input.addEventListener('input', function () {
            if (!dateProblem(input)) {
                showFieldError(input, '');
            }
        });
    });

    // A form is not sent while one of the fields above is wrong; the first one gets the focus.
    document.addEventListener('submit', function (event) {
        var first = null;
        event.target.querySelectorAll('input[data-digits], input[data-decimal], input[type="date"][min], input[type="date"][max]')
            .forEach(function (input) {
                if (input.disabled) {
                    return;
                }
                var problem = input.type === 'date' ? dateProblem(input) : numberProblem(input);
                if (problem) {
                    showFieldError(input, problem);
                    first = first || input;
                }
            });
        if (first) {
            event.preventDefault();
            first.focus();
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        var button = event.submitter;

        // Already stopped by the field checks above.
        if (event.defaultPrevented) {
            return;
        }

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

    // Side menu on small screens. While it is open, the page behind it is dimmed; tapping the
    // dimmed part or pressing Escape closes it.
    var toggle = document.querySelector('.menu-toggle');
    var sidebar = document.getElementById('sidebar');
    if (toggle && sidebar) {
        var backdrop = document.createElement('div');
        backdrop.className = 'nav-backdrop';
        backdrop.hidden = true;
        sidebar.parentNode.insertBefore(backdrop, sidebar.nextSibling);
        var setMenu = function (open) {
            sidebar.classList.toggle('open', open);
            backdrop.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.textContent = open ? 'Close' : 'Menu';
        };
        toggle.addEventListener('click', function () {
            setMenu(!sidebar.classList.contains('open'));
        });
        backdrop.addEventListener('click', function () {
            setMenu(false);
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && sidebar.classList.contains('open')) {
                setMenu(false);
                toggle.focus();
            }
        });
    }

    // Tables on phones and tablets: each row is shown as a card (see .stackable in style.css).
    // Every cell gets its column name in data-label and its content wrapped in .cell-value,
    // so the label and the value sit side by side. Action cells need no label. The roles
    // grid (.matrix) is a grid of checkboxes, and a table without column headers is already
    // a two-column list, so both keep their table layout.
    document.querySelectorAll('.table-wrap > table:not(.matrix)').forEach(function (table) {
        if (!table.tHead) {
            return;
        }
        var labels = [];
        table.querySelectorAll('thead tr:first-child > th').forEach(function (th) {
            for (var i = 0; i < (th.colSpan || 1); i++) {
                labels.push(th.textContent.replace(/\s+/g, ' ').trim());
            }
        });
        table.querySelectorAll('tbody > tr, tfoot > tr').forEach(function (row) {
            var column = 0;
            Array.prototype.forEach.call(row.cells, function (cell) {
                var label = labels[column] || '';
                column += cell.colSpan || 1;
                if (cell.colSpan > 1 || cell.classList.contains('actions-cell') || label === '') {
                    return;
                }
                cell.setAttribute('data-label', label);
                var value = document.createElement('div');
                value.className = 'cell-value';
                while (cell.firstChild) {
                    value.appendChild(cell.firstChild);
                }
                cell.appendChild(value);
            });
        });
        var wrap = table.parentNode;
        wrap.classList.add('stackable');
        if (!table.querySelector('thead th.sortable')) {
            wrap.classList.add('no-sort');
        }
    });

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
