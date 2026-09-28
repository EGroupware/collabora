import {assert} from "@open-wc/testing";
// both of these have to come before "../app" - see CollaboraAppImportStub's own docblock
import "./CollaboraAppImportStub";
import "../../../api/js/etemplate/Et2Widget/Et2Widget";

/**
 * Ticket #125341 ("Send as attachment from collabora: Filename 'vfs://... is not an absolute
 * path!"): collabora/src/Bo.php's own get_token() can store share_path as EITHER the bare VFS
 * path or the "vfs://default"-prefixed one (its own code checks both forms) - so the editor's
 * own `content.path` entry on_save_as_mail() reads can legitimately already carry that url
 * prefix. filemanager.mail()'s open_mail() always unconditionally prepends "vfs://default"
 * itself, assuming a bare path (the normal row-selection convention - id2path() only ever strips
 * a "filemanager::" row-id prefix, never this url prefix) - an already-prefixed path here doubled
 * up into "vfs://defaultvfs://default/...", which Api\Vfs then rejected as "not an absolute
 * path".
 */
describe("collaboraAPP.on_save_as_mail() - vfs://default prefix de-duplication", () =>
{
	async function callWith(contentPath : string) : Promise<{id : string}[]>
	{
		// collaboraAPP itself is not exported - grab it the same way the rest of the framework
		// does, via app.classes.collabora (app.ts's own module-scope self-registration, already
		// triggered by this dynamic import - see CollaboraAppImportStub's own docblock for why
		// `app.classes` must already exist before this import ever runs).
		await import("../app");
		const collaboraAPP = (<any>window).app.classes.collabora;
		const app = Object.create(collaboraAPP.prototype);
		let mailedWith : {id : string}[] = null;

		app.et2 = {
			getArrayMgr: (name : string) => name === 'content' ? {
				getEntry: (key : string, _asString? : boolean) => key === 'path' ? contentPath : undefined,
			} : undefined,
		};
		(<any>window).app.filemanager = {
			mail: (_action : any, selected : {id : string}[]) => { mailedWith = selected; },
		};

		app.on_save_as_mail();

		return mailedWith;
	}

	it("strips an already-prefixed path down to the bare absolute path before handing it to filemanager.mail()", async() =>
	{
		const mailedWith = await callWith("vfs://default/home/stefanu/Täst/dropdwn-test1.odt");

		assert.deepEqual(mailedWith, [{id: "/home/stefanu/Täst/dropdwn-test1.odt"}]);
	});

	it("leaves an already-bare absolute path unchanged", () =>
	{
		return callWith("/home/stefanu/Täst/dropdwn-test1.odt").then(mailedWith =>
		{
			assert.deepEqual(mailedWith, [{id: "/home/stefanu/Täst/dropdwn-test1.odt"}]);
		});
	});
});
