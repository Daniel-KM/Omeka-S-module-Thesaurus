'use strict';

/**
 * Show or hide the conditional fields of the thesaurus import form according to
 * the selected input format and destination.
 */
(function () {
    function fieldOf(id) {
        var el = document.getElementById(id);
        return el ? el.closest('.field') : null;
    }

    function toggle(id, on) {
        var field = fieldOf(id);
        if (field) {
            field.style.display = on ? '' : 'none';
        }
    }

    function checkedValue(name) {
        var el = document.querySelector('input[name="' + name + '"]:checked');
        return el ? el.value : null;
    }

    function update() {
        var format = checkedValue('format');
        toggle('codes', format === 'tab_offset_code_prepended' || format === 'tab_offset_code_appended');
        toggle('skos', format === 'skos');

        var destination = checkedValue('destination');
        toggle('customvocab_label', destination === 'customvocab');
        toggle('customvocab_format', destination === 'customvocab');
        toggle('create_customvocab', destination === 'thesaurus');
    }

    document.addEventListener('change', function (e) {
        if (e.target && (e.target.name === 'format' || e.target.name === 'destination')) {
            update();
        }
    });

    update();
})();
