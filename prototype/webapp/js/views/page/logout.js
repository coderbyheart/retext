/**
 * Kümmert sich um die Anzeige des Logouts
 *
 * @author Markus Tacker <m@tckr.cc>
 */
define([
    'views/page/base',
    'events',
    'remote',
    'text!templates/page/logout.html'
], function (PageViewBase, Events, Remote, PageTemplate) {
    return PageViewBase.extend({
        render:function () {
            $(this.el).html(PageTemplate);
            return this;
        },
        // Logout
        complete:function () {
            $.ajax({
                url:Remote.apiUrlBase + 'logout',
                type:'POST',
                contentType:'application/json',
                dataType:'json',
                context:this,
                error:function () {
                },
                success:function () {
                    Events.trigger('userLogoff');
                }
            });
        }
    });
});