/**
 * Display the selected concept in the resource form.
 *
 * The core prepares the markup only for its own resource data types.
 *
 * @see application/asset/js/resource-form.js
 */
$(document).on('o:prepare-value', function(e, dataType, value, valueObj) {
    if (dataType !== 'resource:concept' || !valueObj) {
        return;
    }
    value.find('span.default').hide();
    const title = typeof valueObj.display_title === 'undefined'
        ? Omeka.jsTranslate('[Untitled]')
        : valueObj.display_title;
    value.find('.selected-resource .o-title')
        .removeClass()
        .addClass('o-title ' + (valueObj.value_resource_name || 'concepts'))
        .html($('<a>', {href: valueObj.url, text: title}));
});
