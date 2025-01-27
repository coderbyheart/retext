/**
 * Kümmert sich um die Anzeige eines Projekts
 *
 * @author Markus Tacker <m@tckr.cc>
 */
define([
    'vm',
    'views/page/base',
    'views/modules/project/breadcrumb',
    'views/modules/project/mode-switcher',
    'views/modules/project/progress',
    'views/modules/project/tree',
    'text!templates/page/project.html',
    'models/projectView',
    'models/project',
    'models/projectprogress',
    'models/container'
], function (Vm, PageViewBase, BreadCrumbView, ModeSwitcherView, ProjectProgressView, ProjectTreeView, ViewTemplate, ProjectViewModel, ProjectModel, ProjectProgressModel, ContainerModel) {
    return PageViewBase.extend({
        template:_.template(ViewTemplate),
        events:{
            'click a.gui-toggle':'toggleCol'
        },
        initialize:function () {
            // IDs are passed in the model param
            var projectId = this.model.id;
            var parentContainerId = this.model.parentContainerId;
            var mode = this.model.mode;
            this.project = new ProjectModel({id:projectId});
            this.parentContainer = new ContainerModel({id:parentContainerId});
            this.parentContainer.bind('change', this.parentContainerFetched, this);
            this.model = new ProjectViewModel({project:this.project, container:this.parentContainer, mode:mode});
        },
        render:function () {
            var el = $(this.el);
            el.html(this.template(this.model.toJSON()));
            el.find('.view-mode-switcher').html(Vm.create(this, 'mode-switcher', ModeSwitcherView, {model:this.model}).el);
            var progressModel = new ProjectProgressModel();
            progressModel.url = this.project.url() + '/progress';
            el.find('.view-context-project-progress').html(Vm.create(this, 'project-progress', ProjectProgressView, {model:progressModel}).el);
            return this;
        },
        parentContainerFetched:function () {
            // Lade View je nach aktuellem Modus
            var that = this;
            require(['views/modules/element/' + this.model.get('mode') + '/list'], function (ElementListView) {
                that.viewFetched(ElementListView);
            });
            $(this.el).find('.view-project-tree').html(Vm.create(this, 'project-tree', ProjectTreeView, {model:this.model}).el);
        },
        viewFetched:function (elementListView) {
            var el = $(this.el);
            var elementList = Vm.create(this, 'current-container', elementListView, {model:this.model});
            el.find('div.view-current-container').html(elementList.el);
            $(this.el).find('a[data-target="#context-tab-' + elementList.preferredContext + '"]').tab('show');
            // Breadcrumb
            Vm.create(this, 'breadcrumb', BreadCrumbView, {el:$(this.el).find('div.view-breadcrumb'), model:this.model});
            // Die Unter-View kann anfordern, dass Context-Informationen angezeigt werden
            elementList.on('contextInfo:show', function (type, view, model) {
                el.find('div.view-context-' + type).html(Vm.create(this, 'context-' + type, view, {model:model}).el);
            });
            elementList.on('contextInfo:clear', function (type) {
                el.find('div.view-context-' + type).html('');
            });
        },
        complete:function () {
            var pc = this.parentContainer;
            this.project.fetch({
                success: function() {
                    pc.fetch();
                }
            }); // Will trigger update an subviews
        },
        toggleCol:function (ev) {
            var a = $(ev.target).closest('a');
            var closeIcon = a.data('closeicon');
            var openIcon = a.data('openicon');
            var icon = a.children('i:first');

            if (icon.hasClass(closeIcon)) {
                icon.removeClass(closeIcon);
                icon.addClass(openIcon);
                this.closeCol(a);
            } else {
                icon.removeClass(openIcon);
                icon.addClass(closeIcon);
                this.openCol(a);
            }
        },
        closeCol:function (a) {
            var div = $(a.data('col'));
            div.detach();
            a.data('div', div);
            var span = parseInt(div.data('openspan'), 10);
            var main = $(this.el).find('div.view-project-main');
            main.removeClass('span' + main.data('currentspan'));
            var mainSpan = parseInt(main.data('currentspan'), 10) + span;
            main.data('currentspan', mainSpan);
            main.addClass('span' + mainSpan);
        },
        openCol:function (a) {
            var div = a.data('div');
            var span = parseInt(div.data('openspan'), 10);
            var main = $(this.el).find('div.view-project-main');
            main.removeClass('span' + main.data('currentspan'));
            var mainSpan = parseInt(main.data('currentspan'), 10) - span;
            main.data('currentspan', mainSpan);
            main.addClass('span' + mainSpan);

            if (div.data('align') == 'left') {
                $(this.el).find('div.gui-cols:first').prepend(div);
            } else {
                $(this.el).find('div.gui-cols:first').append(div);
            }
        }
    });
});
