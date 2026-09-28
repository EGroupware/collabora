/**
 * Globals that have to exist BEFORE collabora/js/app.ts (and the filemanager/api/ import chain it
 * pulls in) is evaluated - import this module ahead of "../app", ESM evaluates imports in
 * declaration order. Same reasoning/shape as mail/js/test/MailAppImportStub.ts, adapted for this
 * app - see that file's own docblock for the full explanation of each stub.
 */
const globals : any = window;

globals.app = globals.app || {classes: {}};
globals.app.classes = globals.app.classes || {};
globals.framework = globals.framework || {setSidebox: () => {}};

if(!globals.jQuery)
{
	const fn : any = {};
	const chainable : any = new Proxy(function() { return chainable; }, {
		get: (target, prop) =>
		{
			if(prop === 'length') return 0;
			if(prop === 'fn') return fn;
			if(prop === 'attr') return () => undefined;
			return () => chainable;
		}
	});
	globals.jQuery = globals.$ = chainable;
}

if(globals.egw)
{
	globals.egw.prefsOnly = true;
	globals.egw.registerJSONPlugin = globals.egw.registerJSONPlugin ?? (() => {});
}

export {};
