/**
 * Don't do anything if customVocabThesaurus is missing.
 */
if (typeof customVocabThesaurus === 'undefined') {
    const customVocabThesaurus = [];
};

/**
 * Improve chosen select for thesarus.
 *
 * A thesaurus can be very big, so limit custom vocab to 10000, unlike in module
 *  CustomVocab.
 *
 * @see CustomVocab/asset/js/resource-form.js
 */
$(document).on('o:prepare-value', function(e, type, value) {

    if (!type.startsWith('customvocab:')) {
        return;
    }

    const customVocabId = Number(type.substring(type.lastIndexOf(':') + 1));
    if (!customVocabThesaurus.includes(customVocabId)) {
        return;
    }

    value.find('select').chosen('destroy');
    value.find('select').chosen({
        width: '100%',
        disable_search_threshold: 25,
        allow_single_deselect: true,
        // // More than 1000 may cause performance issues
        // @see https://github.com/harvesthq/chosen/issues/2580
        max_shown_results: 10000,
    });

});
