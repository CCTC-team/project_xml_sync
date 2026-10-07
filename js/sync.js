// Project XML Sync: selection helpers and apply confirmation
(function () {
    var form = document.getElementById('pxs-apply-form');
    if (!form) return;

    var boxes = function (component) {
        var sel = 'input[type=checkbox][name="items[]"]:not(:disabled)';
        if (component) sel += '[data-component="' + component + '"]';
        return Array.prototype.slice.call(form.querySelectorAll(sel));
    };

    // Mapping grid: each changed cell mirrors (and toggles) its item checkbox
    var boxFor = function (id) {
        return form.querySelector('input[type=checkbox][name="items[]"][value="' + id + '"]');
    };
    var syncGrid = function () {
        Array.prototype.forEach.call(form.querySelectorAll('.pxs-cell[data-item]'), function (cell) {
            var b = boxFor(cell.getAttribute('data-item'));
            cell.classList.toggle('pxs-cell-off', !b || !b.checked);
        });
    };
    Array.prototype.forEach.call(form.querySelectorAll('.pxs-cell[data-item]'), function (cell) {
        cell.addEventListener('click', function () {
            var b = boxFor(cell.getAttribute('data-item'));
            if (!b || b.disabled) return;
            b.checked = !b.checked;
            b.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });

    var updateCount = function () {
        syncGrid();
        var n = boxes().filter(function (b) { return b.checked; }).length;
        var el = document.getElementById('pxs-selected-count');
        if (el) el.textContent = n + ' change(s) selected';
        var btn = document.getElementById('pxs-apply');
        if (btn) btn.disabled = n === 0;
    };

    form.addEventListener('change', updateCount);

    Array.prototype.forEach.call(document.querySelectorAll('.pxs-select'), function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var on = a.getAttribute('data-select') === '1';
            boxes(a.getAttribute('data-component')).forEach(function (b) { b.checked = on; });
            updateCount();
        });
    });

    form.addEventListener('submit', function (e) {
        var selected = boxes().filter(function (b) { return b.checked; });
        var removals = selected.filter(function (b) {
            return b.closest('tr').classList.contains('pxs-row-removed');
        }).length;
        var msg = 'Apply ' + selected.length + ' change(s) to this project?';
        if (removals > 0) msg += '\n\n' + removals + ' of them REMOVE items from this project.';
        msg += '\n\nField changes go into Draft Mode; everything else takes effect immediately.';
        if (!confirm(msg)) e.preventDefault();
    });

    updateCount();
})();
