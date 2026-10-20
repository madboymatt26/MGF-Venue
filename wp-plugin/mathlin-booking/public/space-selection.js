/* One canonical field keeps creation, recurrence, amendments and notifications consistent. */
(function () {
    'use strict';
    function init() {
        document.querySelectorAll('.mbs-space-selector').forEach(function (root) {
            const config = JSON.parse(root.dataset.config), select = document.getElementById(config.target);
            if (!select || root.dataset.ready) return; root.dataset.ready = '1';
            const spaces = config.spaces, separator = ' + ', initial = config.current || select.value;
            if (initial && !Array.from(select.options).some(o => o.value === initial)) select.add(new Option(initial, initial));
            select.value = initial;
            if (!config.enabled) return; // Existing allocations stay visible even when new ones are disabled.
            const toggle = document.createElement('input'); toggle.type = 'checkbox';
            const label = document.createElement('label'); label.append(toggle, document.createTextNode(' Select several areas under one booking'));
            root.appendChild(label);
            const box = document.createElement('fieldset'), legend = document.createElement('legend');
            legend.textContent = 'Areas used for the same dates and times'; box.appendChild(legend);
            box.style.cssText = 'padding:12px;border:1px solid #ddd;margin:8px 0;display:grid;gap:8px'; root.appendChild(box);
            const choices = [];
            Object.keys(spaces).sort().forEach(function (name) {
                const info = spaces[name];
                if (!info.parent || !spaces[info.parent] || name.includes(separator) || Object.values(spaces).some(c => c.parent === name)) return;
                const input = document.createElement('input'); input.type = 'checkbox'; input.value = name;
                input.checked = !spaces[initial] && initial.split(separator).includes(name);
                const row = document.createElement('label'); row.append(input, document.createTextNode(' ' + name)); box.appendChild(row);
                choices.push(input);
            });
            const hint = document.createElement('p'); hint.setAttribute('aria-live', 'polite'); box.appendChild(hint);
            let composite = !spaces[initial] ? Array.from(select.options).find(o => o.value === initial) : null, internal = false;
            function update() {
                box.hidden = !toggle.checked; box.style.display = toggle.checked ? 'grid' : 'none';
                if (!toggle.checked) { if (composite && select.value === composite.value) select.value = ''; return; }
                const selected = choices.filter(c => c.checked).map(c => c.value).sort();
                const parent = selected.length ? spaces[selected[0]].parent : null;
                choices.forEach(c => { c.disabled = !!parent && spaces[c.value].parent !== parent && !c.checked; });
                const value = selected.join(separator);
                if (composite) { composite.remove(); composite = null; }
                if (selected.length > 1) { composite = new Option(value, value); select.add(composite); }
                select.value = value;
                const capacities = selected.map(n => Number(spaces[n].capacity || 0));
                hint.textContent = selected.length ? selected.length + ' area(s). Maximum group size: ' + Math.min.apply(null, capacities) + '. Whole venue is not selected automatically.' : 'Select at least one area.';
                internal = true; select.dispatchEvent(new Event('change', {bubbles:true})); internal = false;
            }
            toggle.checked = !spaces[initial] && initial.includes(separator);
            toggle.addEventListener('change', update); choices.forEach(c => c.addEventListener('change', update));
            select.addEventListener('change', function () {
                if (!internal && spaces[select.value]) {
                    toggle.checked = false; box.hidden = true; box.style.display = 'none';
                    if (composite) { composite.remove(); composite = null; }
                    choices.forEach(c => { c.checked = false; c.disabled = false; });
                }
            });
            if (toggle.checked) update(); else { box.hidden = true; box.style.display = 'none'; }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
}());
