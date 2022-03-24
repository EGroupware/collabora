/**
 * Collabora integration javascript
 *
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @package collabora
 * @copyright (c) 2017-201  Nathan Gray
 */

/*egw:uses
	/filemanager/js/app.js;
 */

import {filemanagerAPP} from "../../filemanager/js/filemanager";
import {EgwApp} from "../../api/js/jsapi/egw_app";
import {et2_createWidget} from "../../api/js/etemplate/et2_core_widget";
import {egw_get_file_editor_prefered_mimes} from "../../api/js/jsapi/egw_global";
import {et2_IInput} from "../../api/js/etemplate/et2_core_interfaces";
import {Et2Dialog} from "../../api/js/etemplate/Et2Dialog/Et2Dialog";

/**
 * UI for filemanager in collabora
 */
class collaboraFilemanagerAPP extends filemanagerAPP
{

	discovery: {};

	/**
	 * Constructor
	 *
	 * @memberOf app.filemanager
	 */
	constructor()
	{
		super();
	}

	/**
	 * This function is called when the etemplate2 object is loaded
	 * and ready.  If you must store a reference to the et2 object,
	 * make sure to clean it up in destroy().
	 *
	 * @param et2 etemplate2 Newly ready object
	 * @param {string} name template name
	 */
	et2_ready(et2,name)
	{
		// call parent
		super.et2_ready(et2, name);
	}

	/**
	 * Open a file in collabora
	 * @param {egwAction} action
	 * @param {egwActionObject[]} selected
	 */
	open(action, selected)
	{
		let data = egw.dataGetUIDdata(selected[0].id);
		let is_collabora = this.et2.getArrayMgr('content').getEntry('is_collabora');
		let dblclick_action = egw.preference('document_doubleclick_action', 'filemanager');

		// Check to see if it's something we can handle
		if (is_collabora && this.isEditable(action, selected) &&
			(!dblclick_action || dblclick_action == 'collabora'))
		{
			// Open the editor in a new window, still under our control
			window.open(egw.link('/index.php', {
				'menuaction': 'collabora.EGroupware\\collabora\\Ui.editor',
				'path': data.data.path,
				'cd': 'no'	// needed to not reload framework in sharing
			}));
		}
		else
		{
			return super.open(action, selected);
		}
	}

	/**
	 * Check to see if the file is editable
	 *
	 * @param {egwAction} _egwAction
	 * @param {egwActionObject[]} _senders
	 * @returns {boolean} returns true if is editable otherwise false
	 */
	isEditable(_egwAction, _senders)
	{
		let data = egw.dataGetUIDdata(_senders[0].id);
		let mime = data && data.data && data.data.mime ? data.data.mime : '';
		if(data && mime && this.discovery && this.discovery[mime])
		{
			let fe = egw_get_file_editor_prefered_mimes(mime);
			if (fe && fe.mime && !fe.mime[mime])
			{
				return false;
			}
			else if (fe && fe.mime && fe.mime[mime] && ['edit'].indexOf(this.discovery[mime].name) == -1)
			{
				return true;
			}
			return ['edit'].indexOf(this.discovery[mime].name) !== -1;
		}
		else
		{
			return super.isEditable(_egwAction, _senders);
		}
	}

	/**
	 * Check to see if we can make a sharable edit link
	 *
	 * @param {egwAction} _egwAction
	 * @param {egwActionObject[]} _selected
	 * @returns {Boolean}
	 */
	isSharableFile(_egwAction, _selected)
	{
		if(_selected.length !== 1) return false;

		let data = egw.dataGetUIDdata(_selected[0].id);
		let mime = data && data.data && data.data.mime ? data.data.mime : '';
		if(data && mime && this.discovery && this.discovery[mime])
		{
			let fe = egw_get_file_editor_prefered_mimes();
			if (fe && fe.mime && !fe.mime[mime]) return false;
			return ['edit'].indexOf(this.discovery[mime].name) !== -1;
		}
		else
		{
			return false;
		}
	}

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
	set_discovery(settings)
	{
		this.discovery = settings;
	}

	/**
	 * create a share-link for the given file
	 * @param {object} _action egw actions
	 * @param {object} _selected selected nm row
	 * @returns {Boolean} returns false if not successful
	 */
	share_collabora_link(_action, _selected) : boolean
	{
		// Check to see if it's something we can handle
		if(this.isEditable(_action, _selected))
		{
			let path = this.id2path(_selected[0].id);
			egw.json('EGroupware\\collabora\\Ui::ajax_share_link', [_action.id, path],
				this._share_link_callback, this, true, this).sendRequest();
			return true;
		}
		return false;
	}

	/**
	 * Use the Collabora conversion API to convert the file to a different format
	 *
	 * We use the same filename.
	 *
	 * @see https://sdk.collaboraonline.com/docs/conversion_api.html
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _selected
	 */
	convert_to(_action, _selected)
	{
		let path = this.id2path(_selected[0].id);
		let msg = this.egw.message(this.egw.lang("Converting..."), "info");
		egw.json('EGroupware\\collabora\\Conversion::ajax_convert', [path, _action.id],
			this._convert_to_callback, this, true, {app: this, msg: msg}).sendRequest();
		return true;
	}

	/**
	 * A file conversion was attempted, give user feedback
	 *
	 * @param data {
	 *	success   : Boolean,
	 *	error_message' : String,
	 *	original_path  : String
	 *	converted_path : String
	 * }
	 */
	_convert_to_callback(data : { success : Boolean, error_message : String, original_path : String, converted_path : String })
	{
		// Clear converting message
		this.msg.close();

		if(!data || !data.success)
		{
			this.app.egw.message(this.app.egw.lang("Conversion failed") + (data.error_message ? "\n" + data.error_message : ""), "error");
			return;
		}
		let msg = this.app.egw.lang("Converted") + "\n" + data.converted_path;

		this.app.egw.refresh(msg, "filemanager", data.converted_path, "add");
	}

	/**
	 * Mail files action: open compose with already linked files
	 * We're only interested in collabora shares here, the super can handle
	 * the rest
	 *
	 * @param {egwAction} _action
	 * @param {egwActionObject[]} _selected
	 */
	mail(_action, _selected)
	{
		if(_action.id.indexOf('collabora') < 0)
		{
			return super.mail(_action, _selected);
		}
		let path = this.id2path(_selected[0].id);
		egw.json('EGroupware\\collabora\\Ui::ajax_share_link', [_action.id, path],
			this._mail_link_callback, this, true, this).sendRequest();
		return true;
	}

	/**
	 * Callback with the share link to append to an email
	 *
	 * @param {Object} _data
	 * @param {String} _data.share_link Link to the share
	 * @param {String} _data.title Title for the link
	 * @param {String} [_data.msg] Error message
	 */
	_mail_link_callback(_data) {
		if (_data.msg || !_data.share_link) window.egw_refresh(_data.msg, this.appname);

		let params = {
			'preset[body]': '<a href="'+_data.share_link + '">'+_data.title+'</a>',
			'mimeType': 'html'// always open compose in html mode, as attachment links look a lot nicer in html
		};
		let content = {
			mail_htmltext: ['<br /><a href="'+_data.share_link + '">'+_data.title+'</a>'],
			mail_plaintext: ["\n"+_data.share_link]
		};
		return egw.openWithinWindow("mail", "setCompose", content, params, /mail.mail_compose.compose/);
	}

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
	_dialog_create_new(_type, _openasnew, _path)
	{
		let current_path = _path || this.et2.getWidgetById('path').get_value();
		let extensions = {};
		let type = _type || 'document';
		let self = this;
		let ext_default = 'odt';
		let title = _openasnew ? this.egw.lang('Open as new') :
			this.egw.lang('Create new %1', type == 'more'? this.egw.lang('file'): this.egw.lang(type));
		// Make first char uppercase, as some languages (German) put the type first
		title = title.charAt(0).toUpperCase() + title.slice(1);

		//list of extensions that we don't want to offer for create new file
		let exclusive_ext = ['odz', 'odb', 'dif', 'slk', 'dbf', 'oxt'];
		switch (type)
		{
			case 'document':
				extensions = {odt:'(.odt) OpenDocument Text', doc: '(.doc) MS Word 97-2003', docx: '(.docx) MS Word'}; break;
			case 'spreadsheet':
				extensions = {ods:'(.ods) OpenDocument Spreadsheet', xls: '(.xls) MS Excel 97-2003', xlsx: '(.xlsx) MS Excel'};
				ext_default = 'ods';
				break;
			case 'presentation':
				extensions = {odp:'(.odp) OpenDocument Presentation', pptx: '(.pptx) MS PowerPoint'};
				ext_default = 'odp';
				break;
			case 'drawing':
				extensions = {odg:'(.odg) OpenDocument Drawing Document Format', otg:'(.otg) OpenDocument Drawing Template Format', fodg:'(.fodg) OpenDocument Flat XML Drawing Format'};
				ext_default = 'odg';
				break;
			case 'more':
				for(let key in this.discovery)
				{
					if(this.discovery[key].name == 'edit' && exclusive_ext.filter(function(v)
					{
						return (self.discovery[key]['ext'] == v);
					}).length == 0)
					{
						extensions[this.discovery[key]['ext']] = '(.' + this.discovery[key]['ext'] + ') ' + key;
					}
				}
				break;
		}
		let dialog = new Et2Dialog(this.egw);
		dialog.transformAttributes({
			callback: function(_button_id, _val)
			{
				if(_button_id == 'create' && _val && _val.name != '')
				{
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
				{id: 'create', label: egw.lang('Create'), image: 'new', default: true},
				{id: 'cancel', label: egw.lang('Cancel'), image: 'close'}
			],
			minWidth: 300,
			minHeight: 200,
			value: {content: {extension: ext_default, openasnew: _openasnew}, 'sel_options': {extension: extensions}},
			template: egw.webserverUrl + '/collabora/templates/default/new.xet?1',
			resizable: false
		});
		document.body.appendChild(dialog);
	}

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
	_request_createNew(data)
	{
		egw.json('EGroupware\\collabora\\Ui::ajax_createNew', [data.ext, data.dir, data.name, data.openasnew], function(_data){
			if (_data.path)
			{
				self.egw.refresh('', 'filemanager');
				window.open(egw.link('/index.php', {
					menuaction: 'collabora.EGroupware\\collabora\\Ui.editor',
					path: _data.path,
					cd: 'no' // needed to not reload framework in sharing
				}));
			}
			egw.message(_data.message);
		}).sendRequest(true);
	}

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
	create_new(_action, _nm) {
		let is_collabora = this.et2.getArrayMgr('content').getEntry('is_collabora');
		let new_widget = this.et2.getWidgetById('new');
		let type = (typeof new_widget._type != 'undefined' && _action['type'] != 'popup')? new_widget.get_value(): _action.id;
		if (is_collabora)
		{
			let id = new_widget[0] && new_widget[0].id ? new_widget[0].id : null;
			let elem = id ? egw.dataGetUIDdata(id) : null;
			let data;
			if (_action.id == 'openasnew')
			{
				data = egw.dataGetUIDdata(new_widget[0].id);
			}
			let path = id ?	id.split('filemanager::')[1]: this.et2.getWidgetById('path').get_value();
			if (elem && elem.data && !elem.data.is_dir) path = this.dirname(path);

			this._dialog_create_new(type, data? data.data.path: undefined, path);
		}
		else
		{
			return super.create_new(_action, new_widget);
		}
		return true;
	}
}
app.classes.filemanager = collaboraFilemanagerAPP;

/**
* UI for collabora stuff
*
* @augments AppJS
*/
class collaboraAPP extends EgwApp
{

	// Handy reference to iframe
	private editor_iframe : HTMLIFrameElement = null;

	// Flag for if we've customized & bound the editor
	private loaded : boolean = false;

	// Mime types supported for export/save as the openned file
	private export_formats : object = {};
	private load: boolean;

	/**
	 * Constructor
	 */
	constructor()
	{
		super("collabora");

		// Filemanager has some handy utilites, but we need to be careful what
		// we use, since it's not actually available
		if(typeof app.filemanager === 'undefined')
		{
			app.filemanager = new app.classes.filemanager;
		}
	}

	/**
	 * This function is called when the etemplate2 object is loaded
	 * and ready.  If you must store a reference to the et2 object,
	 * make sure to clean it up in destroy().
	 *
	 * @param et2 etemplate2 Newly ready object
	 * @param {string} name template name
	 */
	et2_ready(et2,name)
	{
		// call parent
		super.et2_ready(et2, name);
		if(name === 'collabora.editor')
		{
				this.init_editor();
		}
	}

	/**
	 * Override the default to use the file name as title
	 */
	getWindowTitle()
	{
		return egw.config('site_title','phpgwapi') + '[' +
			this.et2.getArrayMgr('content').getEntry('path', true) +
			']';
	}

	/**
	 * Initialize editor and post the form that starts it
	 *
	 * @see https://wopi.readthedocs.io/en/latest/hostpage.html
	 * @param {Object} values
	 */
	init_editor(values?)
	{
		// We allow additional calls and reset, since we're replacing the editor
		this.loaded = false;

		if(typeof values == 'undefined')
		{
			values = this.et2.getArrayMgr('content').data || {};
		}
		values.url += '&user='+this.egw.user('account_lid');
		values.url += '&lang=' + this.egw.preference('lang');
		values.url += '&title=' + encodeURIComponent(values.filename);
		let form_html = jQuery(document.createElement('form')).attr({
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

		let ui_preferences = "UIMode="+ (egw.preference("ui_mode","filemanager") || 'notebookbar');
		jQuery(document.createElement('input')).attr({
			name: "ui_defaults",
			value: ui_preferences,
			type: "hidden"
		}).appendTo(form_html);

		jQuery('body').append(form_html);

		let frameholder = jQuery('.editor_frame');
		let frame = '<iframe id="loleafletframe" name= "loleafletframe" allowfullscreen="" style="height:100%;position:absolute;"/>';

		jQuery('iframe',frameholder).remove();
		frameholder.append(frame);

		// Listen for messages
		window.addEventListener('message', jQuery.proxy(function(e){
			this._handle_messages(e);
		}, this));

		this.editor_iframe = <HTMLIFrameElement>jQuery('#loleafletframe')[0];
		jQuery(frame).on('load', function(){
			// Tell the iframe that we are ready now
			app.collabora.WOPIPostMessage('Host_PostmessageReady', {});
		});

		(<HTMLFormElement>document.getElementById('form')).submit();
	}

	/**
	 * Handle messages sent from the editor
	 *
	 * @see https://www.collaboraoffice.com/collabora-online-editor-api-reference/#loleaflet-postmessage-actions
	 *	for allowed actions
	 * @param {Object} e
	 * @param {String} e.MessageId
	 * @param {Object} e.Values Depends on the message, but always an object, if present
	 */
	_handle_messages(e)
	{
		let message = JSON.parse(e.data);

		switch (message.MessageId)
		{
			case "App_LoadingStatus":
				if (message.Values.Status === 'Document_Loaded' && !this.loaded)
				{
					// Tell the iframe that we are ready now
					app.collabora.WOPIPostMessage('Host_PostmessageReady', {});

					// Get supported export formats
					app.collabora.WOPIPostMessage('Get_Export_Formats');

					// enable FollowUser option by default
					app.collabora.WOPIPostMessage('Action_FollowUser');

					this._customize_editor();
					this.load = true;
				}
				if (message.Values.Status === 'Frame_Ready')
				{
					// If current app is filemanager, refresh it.  Don't refresh non-visible nm or it will be blank.
					if (window.opener && window.opener.app && window.opener.app.filemanager && window.opener.egw_getAppName() === "filemanager")
					{
						let nm = window.opener.app.filemanager.et2.getWidgetById('nm');
						// try to referesh opener nm list, it helps to keep the list up to date
						// in save as changes.
						if (nm) nm.applyFilters();
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
				if(message.Values.Id === 'egwSaveAs' || message.MessageId === 'UI_SaveAs')
				{
					this.on_save_as();
				}
				switch(message.Values.Id)
				{
					case  'egwSaveAsMail':
						this.on_save_as_mail();
						break;
					case 'egwPlaceholder':
						this.on_placeholder_click();
						break;
					case 'egwContactPlaceholder':
						this.on_placeholder_snippet_click();
						break;
				}
				break;
			case "UI_InsertGraphic":
				this.insert_image();
				break;
			case "UI_Share":
				this.share();
				break;
			case "Get_Export_Formats_Resp":
				let fe = egw.link_get_registry('filemanager-editor');
				let discovery = (fe && fe["mime"]) ? fe["mime"]: [];
				if (message.Values)
				{
					for (let i in message.Values)
					{
						for(let j in discovery)
						{
							if(discovery[j]['ext'] == message.Values[i]['Format'])
							{
								this.export_formats[j] = discovery[j];
							}
						}
					}
				}
				break;
			case "File_Rename":
				this.egw.message(this.egw.lang("File renamed"));
				// Update our value for path, or next time we do something with it (Save as again, email)
				// it will be the original value
				this.et2.getArrayMgr('content').data.path =
					app.filemanager.dirname(this.et2.getArrayMgr('content').data.path) + '/' +
					message.Values.NewName;
				break;
		}
	}

	/**
	 * Pass a message into the editor
	 *
	 * @see https://www.collaboraoffice.com/collabora-online-editor-api-reference/#loleaflet-postmessage-actions
	 *	for allowed actions
	 */
	WOPIPostMessage(msgId, values?)
	{
		if(this.editor_iframe)
		{
			let msg = {
				'MessageId': msgId,
				'SendTime': Date.now(),
				'Values': values
			};

			this.editor_iframe.contentWindow.postMessage(JSON.stringify(msg), '*');
		}
	}

	/**
	 * Do our customizations of the editor
	 *
	 * This is where we add buttons and menu actions and such
	 */
	_customize_editor() {

		let baseUrl = egw.webserverUrl[0] == '/' ?
			window.location.protocol+'//'+window.location.hostname+egw.webserverUrl
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
		this.WOPIPostMessage('Insert_Button', {
			id: 'egwPlaceholder',
			imgurl: this.egw.image('curly_brackets_icon', 'collabora').replace(egw.webserverUrl, baseUrl),
			hint: this.egw.lang('Insert placeholder')
		});
		this.WOPIPostMessage('Insert_Button', {
			id: 'egwContactPlaceholder',
			imgurl: this.egw.image('navbar', 'addressbook').replace(egw.webserverUrl, baseUrl),
			hint: this.egw.lang('Insert address')
		});
	}

	/**
	 * Handle Save as mail button
	 */
	on_save_as_mail() {
		let filepath = this.et2.getArrayMgr('content').getEntry('path', true);
		app.filemanager.mail({id:"attach"}, [{id:filepath}]);
	}

	/**
	 * Handle close button
	 *
	 * This is just the default, not sure if we need any more
	 */
	on_close()
	{
		// Do not ask if they're sure, it's too late.  Just reset dirty
		this.et2.iterateOver(function(w) {w.resetDirty();},this,et2_IInput);
		window.close();
	}

	/**
	 * Handle click on Save As
	 */
	on_save_as()
	{
		let filepath = this.et2.getArrayMgr('content').getEntry('path', true);
		let parts = app.filemanager.basename(filepath).split('.');
		let ext = parts.pop();
		let filename = parts.join('.');

		// select current mime-type
		let mime_types = [];
		for(let mime in this.export_formats) {
			if (this.export_formats[mime].ext == ext) {
				mime_types.unshift(mime);
			} else {
				mime_types.push(mime);
			}
		}

		// create file selector
		let vfs_select = et2_createWidget('vfs-select', {
			id:'savefile',
			mode: 'saveas',
			name: filename,
			path: app.filemanager.dirname(filepath),
			button_caption: "",
			button_label: egw.lang("Save as"),
			mime: mime_types
		}, this.et2);
		let self = this;

		// bind change handler for setting the selected path and calling save
		window.setTimeout(function() {
			jQuery(vfs_select.getDOMNode()).on('change', function (){
				let file_path = vfs_select.get_value();
				let selectedMime = self.export_formats[vfs_select.dialog.value.mime];
				// only add extension, if not already there
				if(selectedMime && file_path.substr(-selectedMime.ext.length - 1) !== '.' + selectedMime.ext)
				{
					file_path += '.' + selectedMime.ext;
				}
				if(file_path)
				{
					// Update our value for path, or next time we do something with it (Save as again, email)
					// it will be the original value
					self.et2.getArrayMgr('content').data.path = file_path;
					
					self.WOPIPostMessage('Action_SaveAs', {
						Filename: file_path
					});
				}
			});
		}, 1);
		// start the file selector dialog
		vfs_select.click();
	}

	/**
	 * User wants to insert a merge placeholder.  Open the dialog
	 */
	on_placeholder_click()
	{
		// create placeholder selector
		let selector = et2_createWidget('placeholder-select', {
			id: 'placeholder',
			insert_callback: (text) =>
			{
				this.insert_text(text);
			}
		}, this.et2);
		// Don't know what's going wrong with the parenting, selector fails to get parent which screws up
		// where this.egw() points, which breaks getting the translations from Collabora lang files
		this.et2.addChild(selector);

		selector.doLoadingFinished();
	}

	/**
	 * User wants to insert a contact from placeholder.  Open the snippet dialog
	 */
	on_placeholder_snippet_click()
	{
		// create placeholder selector
		let selector = et2_createWidget('placeholder-snippet', {
			id: 'snippet',
			insert_callback: (text) =>
			{
				this.insert_text(text);
			}
		}, this.et2);
		// Don't know what's going wrong with the parenting, selector fails to get parent which screws up
		// where this.egw() points, which breaks getting the translations from Collabora lang files
		this.et2.addChild(selector);
		selector.doLoadingFinished();
	}

	/**
	 * Show the revision history list for the file
	 */
	show_revision_history()
	{
		jQuery(this.et2.getInstanceManager().DOMContainer).addClass('revisions');
	}

	/**
	 * Hide the revision history list
	 */
	close_revision_history()
	{
		jQuery(this.et2.getInstanceManager().DOMContainer).removeClass('revisions');
	}

	/**
	 * Edit a particular revision of a file, selected from a list
	 */
	edit_revision(event, widget)
	{
		let row = widget.getArrayMgr('content').explodeKey(widget.id).pop()||0;
		let revision = widget.getArrayMgr('content').getEntry(row);
		window.location.href = egw.link('/index.php', {
			'menuaction': 'collabora.EGroupware\\collabora\\Ui.editor',
			'path': revision.path,
			'cd': 'no'	// needed to not reload framework in sharing
		});
		return false;
	}

	/**
	 * Get a URL to insert into the document
	 */
	insert_image()
	{
		let image_selected = function(node, widget)
		{
			if(widget.get_value())
			{
				// Collabora server probably doesn't have access to file, so share it
				// It needs access, but only to fetch the file so expire tomorrow.
				// Collabora will fail (hang) if share dissapears while the document is open
				let expires = new Date();
				expires.setUTCDate(expires.getUTCDate()+1);
				this.egw.json('EGroupware\\Api\\Sharing::ajax_create',
					['collabora', widget.get_value(), false, false, {share_expires: date('Y-m-d',expires)}],
					function(value) {
						// Tell Collabora about it - add '/' to the end to avoid redirect by WebDAV server
						// (WebDAV/Server.php line 247
						this.WOPIPostMessage('Action_InsertGraphic', {url:value.share_link+'/'});
					},
					this, true,this,this.egw).sendRequest();
			}
		}.bind(this);
		let attrs = {
			mode: 'open',
			dialog_title: this.egw.lang('Insert'),
			button_label: this.egw.lang('Insert'),
			onchange: image_selected
		};
		let select = et2_createWidget('vfs-select', attrs, this.et2);
		select.loadingFinished();
		select.click();
	}

	/**
	 * Insert (paste) some text into the document
	 */
	insert_text(text, mimetype = "text/plain;charset=utf-8")
	{
		console.log(text, mimetype);
		this.WOPIPostMessage(
			"Action_Paste",
			{Mimetype: mimetype, Data: text}
		);
		debugger;
	}

	/**
	 * Share the current file (via mail)
	 *
	 * @returns {Boolean}
	 */
	share()
	{
		let path = this.et2.getArrayMgr('content').getEntry('path');
		egw.json('EGroupware\\collabora\\Ui::ajax_share_link', ['mail_collabora', path],
			app.filemanager._mail_link_callback, this, true, this).sendRequest();
		return true;
	}
}
app.classes.collabora = collaboraAPP;