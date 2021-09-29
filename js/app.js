"use strict";
/**
 * Collabora integration javascript
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017  Nathan Gray
 */
var __extends = (this && this.__extends) || (function () {
    var extendStatics = function (d, b) {
        extendStatics = Object.setPrototypeOf ||
            ({ __proto__: [] } instanceof Array && function (d, b) { d.__proto__ = b; }) ||
            function (d, b) { for (var p in b) if (b.hasOwnProperty(p)) d[p] = b[p]; };
        return extendStatics(d, b);
    };
    return function (d, b) {
        extendStatics(d, b);
        function __() { this.constructor = d; }
        d.prototype = b === null ? Object.create(b) : (__.prototype = b.prototype, new __());
    };
})();
Object.defineProperty(exports, "__esModule", { value: true });
/*egw:uses
    /filemanager/js/app.js;
 */
var app_1 = require("../../filemanager/js/app");
var egw_app_1 = require("../../api/js/jsapi/egw_app");
var et2_widget_dialog_1 = require("../../api/js/etemplate/et2_widget_dialog");
var et2_core_widget_1 = require("../../api/js/etemplate/et2_core_widget");
/**
 * UI for filemanager in collabora
 */
var collaboraFilemanagerAPP = /** @class */ (function (_super) {
    __extends(collaboraFilemanagerAPP, _super);
    /**
     * Constructor
     *
     * @memberOf app.filemanager
     */
    function collaboraFilemanagerAPP() {
        return _super.call(this) || this;
    }
    /**
     * This function is called when the etemplate2 object is loaded
     * and ready.  If you must store a reference to the et2 object,
     * make sure to clean it up in destroy().
     *
     * @param et2 etemplate2 Newly ready object
     * @param {string} name template name
     */
    collaboraFilemanagerAPP.prototype.et2_ready = function (et2, name) {
        // call parent
        _super.prototype.et2_ready.call(this, et2, name);
    };
    /**
     * Open a file in collabora
     * @param {egwAction} action
     * @param {egwActionObject[]} selected
     */
    collaboraFilemanagerAPP.prototype.open = function (action, selected) {
        var data = egw.dataGetUIDdata(selected[0].id);
        var is_collabora = this.et2.getArrayMgr('content').getEntry('is_collabora');
        var dblclick_action = egw.preference('document_doubleclick_action', 'filemanager');
        // Check to see if it's something we can handle
        if (is_collabora && this.isEditable(action, selected) &&
            (!dblclick_action || dblclick_action == 'collabora')) {
            // Open the editor in a new window, still under our control
            window.open(egw.link('/index.php', {
                'menuaction': 'collabora.EGroupware\\collabora\\Ui.editor',
                'path': data.data.path,
                'cd': 'no' // needed to not reload framework in sharing
            }));
        }
        else {
            return _super.prototype.open.call(this, action, selected);
        }
    };
    /**
     * Check to see if the file is editable
     *
     * @param {egwAction} _egwAction
     * @param {egwActionObject[]} _senders
     * @returns {boolean} returns true if is editable otherwise false
     */
    collaboraFilemanagerAPP.prototype.isEditable = function (_egwAction, _senders) {
        var data = egw.dataGetUIDdata(_senders[0].id);
        var mime = data && data.data && data.data.mime ? data.data.mime : '';
        if (data && mime && this.discovery && this.discovery[mime]) {
            var fe = egw_get_file_editor_prefered_mimes(mime);
            if (fe && fe.mime && !fe.mime[mime]) {
                return false;
            }
            else if (fe && fe.mime && fe.mime[mime] && ['edit'].indexOf(this.discovery[mime].name) == -1) {
                return true;
            }
            return ['edit'].indexOf(this.discovery[mime].name) !== -1;
        }
        else {
            return _super.prototype.isEditable.call(this, _egwAction, _senders);
        }
    };
    /**
     * Check to see if we can make a sharable edit link
     *
     * @param {egwAction} _egwAction
     * @param {egwActionObject[]} _selected
     * @returns {Boolean}
     */
    collaboraFilemanagerAPP.prototype.isSharableFile = function (_egwAction, _selected) {
        if (_selected.length !== 1)
            return false;
        var data = egw.dataGetUIDdata(_selected[0].id);
        var mime = data && data.data && data.data.mime ? data.data.mime : '';
        if (data && mime && this.discovery && this.discovery[mime]) {
            var fe = egw_get_file_editor_prefered_mimes();
            if (fe && fe.mime && !fe.mime[mime])
                return false;
            return ['edit'].indexOf(this.discovery[mime].name) !== -1;
        }
        else {
            return false;
        }
    };
    /**
     * Set the list of what the collabora server can handle.
     *
     * The list is indexed by mimetype
     *
     * @param {Object[]} settings
     * @param {string} settings[].name The name of the action (edit)
     * @param {string} settings[].ext File extension
     * @param {string} settings[].urlsrc URL to edit the file.  It still needs
     *	a WOPISrc parameter added though.
     */
    collaboraFilemanagerAPP.prototype.set_discovery = function (settings) {
        this.discovery = settings;
    };
    /**
     * create a share-link for the given file
     * @param {object} _action egw actions
     * @param {object} _selected selected nm row
     * @returns {Boolean} returns false if not successful
     */
    collaboraFilemanagerAPP.prototype.share_collabora_link = function (_action, _selected) {
        // Check to see if it's something we can handle
        if (this.isEditable(_action, _selected)) {
            var path = this.id2path(_selected[0].id);
            egw.json('EGroupware\\collabora\\Ui::ajax_share_link', [_action.id, path], this._share_link_callback, this, true, this).sendRequest();
            return true;
        }
        return false;
    };
    /**
     * Mail files action: open compose with already linked files
     * We're only interested in collabora shares here, the super can handle
     * the rest
     *
     * @param {egwAction} _action
     * @param {egwActionObject[]} _selected
     */
    collaboraFilemanagerAPP.prototype.mail = function (_action, _selected) {
        if (_action.id.indexOf('collabora') < 0) {
            return _super.prototype.mail.call(this, _action, _selected);
        }
        var path = this.id2path(_selected[0].id);
        egw.json('EGroupware\\collabora\\Ui::ajax_share_link', [_action.id, path], this._mail_link_callback, this, true, this).sendRequest();
        return true;
    };
    /**
     * Callback with the share link to append to an email
     *
     * @param {Object} _data
     * @param {String} _data.share_link Link to the share
     * @param {String} _data.title Title for the link
     * @param {String} [_data.msg] Error message
     */
    collaboraFilemanagerAPP.prototype._mail_link_callback = function (_data) {
        if (_data.msg || !_data.share_link)
            window.egw_refresh(_data.msg, this.appname);
        var params = {
            'preset[body]': '<a href="' + _data.share_link + '">' + _data.title + '</a>',
            'mimeType': 'html' // always open compose in html mode, as attachment links look a lot nicer in html
        };
        var content = {
            mail_htmltext: ['<br /><a href="' + _data.share_link + '">' + _data.title + '</a>'],
            mail_plaintext: ["\n" + _data.share_link]
        };
        return egw.openWithinWindow("mail", "setCompose", content, params, /mail.mail_compose.compose/);
    };
    /**
     * Build a dialog to get name and ext of new file
     *
     * @param {string} _type = document type of file, document is default
     *  Types:
     *		-document
     *		-spreadsheet
     *		-presentation
     *		-mores
     * @param {string} _openasnew path of file to be opened as new
     * @param {string} _path path to store the file
     */
    collaboraFilemanagerAPP.prototype._dialog_create_new = function (_type, _openasnew, _path) {
        var current_path = _path || this.et2.getWidgetById('path').get_value();
        var extensions = {};
        var type = _type || 'document';
        var self = this;
        var ext_default = 'odt';
        var title = _openasnew ? this.egw.lang('Open as new') :
            this.egw.lang('Create new %1', type == 'more' ? this.egw.lang('file') : this.egw.lang(type));
        // Make first char uppercase, as some languages (German) put the type first
        title = title.charAt(0).toUpperCase() + title.slice(1);
        //list of extensions that we don't want to offer for create new file
        var exclusive_ext = ['odz', 'odb', 'dif', 'slk', 'dbf', 'oxt'];
        switch (type) {
            case 'document':
                extensions = { odt: '(.odt) OpenDocument Text', doc: '(.doc) MS Word 97-2003', docx: '(.docx) MS Word' };
                break;
            case 'spreadsheet':
                extensions = { ods: '(.ods) OpenDocument Spreadsheet', xls: '(.xls) MS Excel 97-2003', xlsx: '(.xlsx) MS Excel' };
                ext_default = 'ods';
                break;
            case 'presentation':
                extensions = { odp: '(.odp) OpenDocument Presentation', pptx: '(.pptx) MS PowerPoint' };
                ext_default = 'odp';
                break;
            case 'drawing':
                extensions = { odg: '(.odg) OpenDocument Drawing Document Format', otg: '(.otg) OpenDocument Drawing Template Format', fodg: '(.fodg) OpenDocument Flat XML Drawing Format' };
                ext_default = 'odg';
                break;
            case 'more':
                var _loop_1 = function (key) {
                    if (this_1.discovery[key].name == 'edit' && exclusive_ext.filter(function (v) {
                        return (self.discovery[key]['ext'] == v);
                    }).length == 0)
                        extensions[this_1.discovery[key]['ext']] = '(.' + this_1.discovery[key]['ext'] + ') ' + key;
                };
                var this_1 = this;
                for (var key in this.discovery) {
                    _loop_1(key);
                }
                break;
        }
        et2_core_widget_1.et2_createWidget("dialog", {
            callback: function (_button_id, _val) {
                if (_button_id == 'create' && _val && _val.name != '') {
                    self._request_createNew({
                        name: _val.name,
                        openasnew: _openasnew,
                        ext: _openasnew ? _openasnew.split('.').pop() : _val.extension,
                        dir: current_path
                    });
                }
            },
            title: title,
            buttons: [
                { id: 'create', text: egw.lang('Create'), image: 'new', default: true },
                { id: 'cancel', text: egw.lang('Cancel'), image: 'close' }
            ],
            minWidth: 300,
            minHeight: 200,
            value: { content: { extension: ext_default, openasnew: _openasnew }, 'sel_options': { extension: extensions } },
            template: egw.webserverUrl + '/collabora/templates/default/new.xet?1',
            resizable: false
        }, et2_widget_dialog_1.et2_dialog._create_parent('collabora'));
    };
    /**
     * Method to request create new file or open as new file
     * @param {object} data
     *	data: {
     *		name: //filename
     *		ext: //file extension
     *		dir: //directory
     *		openasnew: //path of the file to be opened as new
     *	}
     */
    collaboraFilemanagerAPP.prototype._request_createNew = function (data) {
        egw.json('EGroupware\\collabora\\Ui::ajax_createNew', [data.ext, data.dir, data.name, data.openasnew], function (_data) {
            if (_data.path) {
                self.egw.refresh('', 'filemanager');
                window.open(egw.link('/index.php', {
                    menuaction: 'collabora.EGroupware\\collabora\\Ui.editor',
                    path: _data.path,
                    cd: 'no' // needed to not reload framework in sharing
                }));
            }
            egw.message(_data.message);
        }).sendRequest(true);
    };
    /**
     * Method to create a new document
     *
     * @param {object} _action either action or node
     * @param {object} _nm nm widget
     *
     * @return {boolean} returns true
     *
     * @TODO Implementing of create new type of the file for collabora
     */
    collaboraFilemanagerAPP.prototype.create_new = function (_action, _nm) {
        var is_collabora = this.et2.getArrayMgr('content').getEntry('is_collabora');
        var new_widget = this.et2.getWidgetById('new');
        var type = (typeof new_widget._type != 'undefined' && _action['type'] != 'popup') ? new_widget.get_value() : _action.id;
        if (is_collabora) {
            var id = new_widget[0] && new_widget[0].id ? new_widget[0].id : null;
            var elem = id ? egw.dataGetUIDdata(id) : null;
            var data = void 0;
            if (_action.id == 'openasnew') {
                data = egw.dataGetUIDdata(new_widget[0].id);
            }
            var path = id ? id.split('filemanager::')[1] : this.et2.getWidgetById('path').get_value();
            if (elem && elem.data && !elem.data.is_dir)
                path = this.dirname(path);
            this._dialog_create_new(type, data ? data.data.path : undefined, path);
        }
        else {
            return _super.prototype.create_new.call(this, _action, new_widget);
        }
        return true;
    };
    return collaboraFilemanagerAPP;
}(app_1.filemanagerAPP));
app.classes.filemanager = collaboraFilemanagerAPP;
/**
* UI for collabora stuff
*
* @augments AppJS
*/
var collaboraAPP = /** @class */ (function (_super) {
    __extends(collaboraAPP, _super);
    /**
     * Constructor
     */
    function collaboraAPP() {
        var _this = _super.call(this, "collabora") || this;
        // Handy reference to iframe
        _this.editor_iframe = null;
        // Flag for if we've customized & bound the editor
        _this.loaded = false;
        // Mime types supported for export/save as the openned file
        _this.export_formats = {};
        // Filemanager has some handy utilites, but we need to be careful what
        // we use, since it's not actually available
        if (typeof app.filemanager === 'undefined') {
            app.filemanager = new app.classes.filemanager;
        }
        return _this;
    }
    /**
     * This function is called when the etemplate2 object is loaded
     * and ready.  If you must store a reference to the et2 object,
     * make sure to clean it up in destroy().
     *
     * @param et2 etemplate2 Newly ready object
     * @param {string} name template name
     */
    collaboraAPP.prototype.et2_ready = function (et2, name) {
        // call parent
        _super.prototype.et2_ready.call(this, et2, name);
        if (name === 'collabora.editor') {
            this.init_editor();
        }
    };
    /**
     * Override the default to use the file name as title
     */
    collaboraAPP.prototype.getWindowTitle = function () {
        return egw.config('site_title', 'phpgwapi') + '[' +
            this.et2.getArrayMgr('content').getEntry('path', true) +
            ']';
    };
    /**
     * Initialize editor and post the form that starts it
     *
     * @see https://wopi.readthedocs.io/en/latest/hostpage.html
     * @param {Object} values
     */
    collaboraAPP.prototype.init_editor = function (values) {
        // We allow additional calls and reset, since we're replacing the editor
        this.loaded = false;
        if (typeof values == 'undefined') {
            values = this.et2.getArrayMgr('content').data || {};
        }
        values.url += '&user=' + this.egw.user('account_lid');
        values.url += '&lang=' + this.egw.preference('lang');
        values.url += '&title=' + encodeURIComponent(values.filename);
        var form_html = jQuery(document.createElement('form')).attr({
            id: "form",
            name: "form",
            target: "loleafletframe",
            action: values.url,
            method: "post",
        });
        jQuery(document.createElement('input')).attr({
            name: "access_token",
            value: values.token,
            type: "hidden"
        }).appendTo(form_html);
        var ui_preferences = "UIMode=" + (egw.preference("ui_mode", "filemanager") || 'notebookbar');
        jQuery(document.createElement('input')).attr({
            name: "ui_defaults",
            value: ui_preferences,
            type: "hidden"
        }).appendTo(form_html);
        jQuery('body').append(form_html);
        var frameholder = jQuery('.editor_frame');
        var frame = '<iframe id="loleafletframe" name= "loleafletframe" allowfullscreen="" style="height:100%;position:absolute;"/>';
        jQuery('iframe', frameholder).remove();
        frameholder.append(frame);
        // Listen for messages
        window.addEventListener('message', jQuery.proxy(function (e) {
            this._handle_messages(e);
        }, this));
        this.editor_iframe = jQuery('#loleafletframe')[0];
        jQuery(frame).on('load', function () {
            // Tell the iframe that we are ready now
            app.collabora.WOPIPostMessage('Host_PostmessageReady', {});
        });
        document.getElementById('form').submit();
    };
    /**
     * Handle messages sent from the editor
     *
     * @see https://www.collaboraoffice.com/collabora-online-editor-api-reference/#loleaflet-postmessage-actions
     *	for allowed actions
     * @param {Object} e
     * @param {String} e.MessageId
     * @param {Object} e.Values Depends on the message, but always an object, if present
     */
    collaboraAPP.prototype._handle_messages = function (e) {
        var message = JSON.parse(e.data);
        switch (message.MessageId) {
            case "App_LoadingStatus":
                if (message.Values.Status === 'Document_Loaded' && !this.loaded) {
                    // Tell the iframe that we are ready now
                    app.collabora.WOPIPostMessage('Host_PostmessageReady', {});
                    // Get supported export formats
                    app.collabora.WOPIPostMessage('Get_Export_Formats');
                    // enable FollowUser option by default
                    app.collabora.WOPIPostMessage('Action_FollowUser');
                    this._customize_editor();
                    this.load = true;
                }
                if (message.Values.Status === 'Frame_Ready') {
                    // If current app is filemanager, refresh it.  Don't refresh non-visible nm or it will be blank.
                    if (window.opener && window.opener.app && window.opener.app.filemanager && window.opener.egw_getAppName() === "filemanager") {
                        var nm = window.opener.app.filemanager.et2.getWidgetById('nm');
                        // try to referesh opener nm list, it helps to keep the list up to date
                        // in save as changes.
                        if (nm)
                            nm.applyFilters();
                    }
                }
                break;
            case "UI_Close":
                this.on_close();
                break;
            case "rev-history":
                this.show_revision_history();
                break;
            case "Clicked_Button":
            case "UI_SaveAs":
                if (message.Values.Id === 'egwSaveAs' || message.MessageId === 'UI_SaveAs') {
                    this.on_save_as();
                }
                if (message.Values.Id === 'egwSaveAsMail') {
                    this.on_save_as_mail();
                }
                break;
            case "UI_InsertGraphic":
                this.insert_image();
                break;
            case "UI_Share":
                this.share();
                break;
            case "Get_Export_Formats_Resp":
                var fe = egw.link_get_registry('filemanager-editor');
                var discovery = (fe && fe["mime"]) ? fe["mime"] : [];
                if (message.Values) {
                    for (var i in message.Values) {
                        for (var j in discovery) {
                            if (discovery[j]['ext'] == message.Values[i]['Format']) {
                                this.export_formats[j] = discovery[j];
                            }
                        }
                    }
                }
                break;
            case "File_Rename":
                this.egw.message(this.egw.lang("File renamed"));
                break;
        }
    };
    /**
     * Pass a message into the editor
     *
     * @see https://www.collaboraoffice.com/collabora-online-editor-api-reference/#loleaflet-postmessage-actions
     *	for allowed actions
     */
    collaboraAPP.prototype.WOPIPostMessage = function (msgId, values) {
        if (this.editor_iframe) {
            var msg = {
                'MessageId': msgId,
                'SendTime': Date.now(),
                'Values': values
            };
            this.editor_iframe.contentWindow.postMessage(JSON.stringify(msg), '*');
        }
    };
    /**
     * Do our customizations of the editor
     *
     * This is where we add buttons and menu actions and such
     */
    collaboraAPP.prototype._customize_editor = function () {
        var baseUrl = egw.webserverUrl[0] == '/' ?
            window.location.protocol + '//' + window.location.hostname + egw.webserverUrl
            : egw.webserverUrl;
        this.WOPIPostMessage('Insert_Button', {
            id: 'egwSaveAsMail',
            imgurl: this.egw.image('save_as_mail', 'collabora').replace(egw.webserverUrl, baseUrl),
            hint: this.egw.lang('Save As Mail')
        });
        this.WOPIPostMessage('Insert_Button', {
            id: 'egwSaveAs',
            imgurl: 'images/lc_saveas.svg',
            hint: this.egw.lang('Save As')
        });
    };
    /**
     * Handle Save as mail button
     */
    collaboraAPP.prototype.on_save_as_mail = function () {
        var filepath = this.et2.getArrayMgr('content').getEntry('path', true);
        app.filemanager.mail({ id: "attach" }, [{ id: filepath }]);
    };
    /**
     * Handle close button
     *
     * This is just the default, not sure if we need any more
     */
    collaboraAPP.prototype.on_close = function () {
        // Do not ask if they're sure, it's too late.  Just reset dirty
        this.et2.iterateOver(function (w) { w.resetDirty(); }, this, et2_IInput);
        window.close();
    };
    /**
     * Handle click on Save As
     */
    collaboraAPP.prototype.on_save_as = function () {
        var filepath = this.et2.getArrayMgr('content').getEntry('path', true);
        var parts = app.filemanager.basename(filepath).split('.');
        var ext = parts.pop();
        var filename = parts.join('.');
        // select current mime-type
        var mime_types = [];
        for (var mime in this.export_formats) {
            if (this.export_formats[mime].ext == ext) {
                mime_types.unshift(mime);
            }
            else {
                mime_types.push(mime);
            }
        }
        // create file selector
        var vfs_select = et2_core_widget_1.et2_createWidget('vfs-select', {
            id: 'savefile',
            mode: 'saveas',
            name: filename,
            path: app.filemanager.dirname(filepath),
            button_caption: "",
            button_label: egw.lang("Save as"),
            mime: mime_types
        }, this.et2);
        var self = this;
        // bind change handler for setting the selected path and calling save
        window.setTimeout(function () {
            jQuery(vfs_select.getDOMNode()).on('change', function () {
                var file_path = vfs_select.get_value();
                var selectedMime = self.export_formats[vfs_select.dialog.options.value.content.mime];
                // only add extension, if not already there
                if (selectedMime && file_path.substr(-selectedMime.ext.length - 1) !== '.' + selectedMime.ext) {
                    file_path += '.' + selectedMime.ext;
                }
                if (file_path) {
                    self.WOPIPostMessage('Action_SaveAs', {
                        Filename: file_path
                    });
                }
            });
        }, 1);
        // start the file selector dialog
        vfs_select.click();
    };
    /**
     * Show the revision history list for the file
     */
    collaboraAPP.prototype.show_revision_history = function () {
        jQuery(this.et2.getInstanceManager().DOMContainer).addClass('revisions');
    };
    /**
     * Hide the revision history list
     */
    collaboraAPP.prototype.close_revision_history = function () {
        jQuery(this.et2.getInstanceManager().DOMContainer).removeClass('revisions');
    };
    /**
     * Edit a particular revision of a file, selected from a list
     */
    collaboraAPP.prototype.edit_revision = function (event, widget) {
        var row = widget.getArrayMgr('content').explodeKey(widget.id).pop() || 0;
        var revision = widget.getArrayMgr('content').getEntry(row);
        window.location.href = egw.link('/index.php', {
            'menuaction': 'collabora.EGroupware\\collabora\\Ui.editor',
            'path': revision.path,
            'cd': 'no' // needed to not reload framework in sharing
        });
        return false;
    };
    /**
     * Get a URL to insert into the document
     */
    collaboraAPP.prototype.insert_image = function () {
        var image_selected = function (node, widget) {
            if (widget.get_value()) {
                // Collabora server probably doesn't have access to file, so share it
                // It needs access, but only to fetch the file so expire tomorrow.
                // Collabora will fail (hang) if share dissapears while the document is open
                var expires = new Date();
                expires.setUTCDate(expires.getUTCDate() + 1);
                this.egw.json('EGroupware\\Api\\Sharing::ajax_create', ['collabora', widget.get_value(), false, false, { share_expires: date('Y-m-d', expires) }], function (value) {
                    // Tell Collabora about it - add '/' to the end to avoid redirect by WebDAV server
                    // (WebDAV/Server.php line 247
                    this.WOPIPostMessage('Action_InsertGraphic', { url: value.share_link + '/' });
                }, this, true, this, this.egw).sendRequest();
            }
        }.bind(this);
        var attrs = {
            mode: 'open',
            dialog_title: this.egw.lang('Insert'),
            button_label: this.egw.lang('Insert'),
            onchange: image_selected
        };
        var select = et2_core_widget_1.et2_createWidget('vfs-select', attrs, this.et2);
        select.loadingFinished();
        select.click();
    };
    /**
     * Share the current file (via mail)
     *
     * @returns {Boolean}
     */
    collaboraAPP.prototype.share = function () {
        var path = this.et2.getArrayMgr('content').getEntry('path');
        egw.json('EGroupware\\collabora\\Ui::ajax_share_link', ['mail_collabora', path], app.filemanager._mail_link_callback, this, true, this).sendRequest();
        return true;
    };
    return collaboraAPP;
}(egw_app_1.EgwApp));
app.classes.collabora = collaboraAPP;
//# sourceMappingURL=app.js.map