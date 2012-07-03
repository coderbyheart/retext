/**
 * Anzeige der Container in der Übersetzen-Ansicht
 *
 * @author Markus Tacker <m@tckr.cc>
 */
define([
    'views/modules/element/element',
    'text!templates/modules/element/write/container.html'
], function (ElementView, ViewTemplate) {
    return ElementView.extend({
        template:_.template(ViewTemplate),
        className:'gui-element gui-container'
    });
});
