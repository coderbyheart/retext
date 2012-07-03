/**
 * Zeigt die Änderungshistorie zu einem Text-Block an
 *
 * @author Markus Tacker <m@tckr.cc>
 */
define([
    'views/modules/history',
    'text!templates/modules/texthistory.html'
], function (HistoryView, ModuleTemplate) {
    return HistoryView.extend({
        template:_.template(ModuleTemplate),
        className:'texthistory'
    });
})
;
