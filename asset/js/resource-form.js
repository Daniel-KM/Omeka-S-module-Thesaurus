// Add Chosen UI to the thesaurus data type selects in the resource form.
$(document).on('o:prepare-value', function(e, type, value) {
    if (typeof type === 'undefined' || !type.startsWith('thesaurus:')) {
        return;
    }
    value.find('select').chosen({
        width: '100%',
        disable_search_threshold: 25,
        allow_single_deselect: true,
        // More than 1000 may cause performance issues.
        // @see https://github.com/harvesthq/chosen/issues/2580
        max_shown_results: 1000,
    });
});
