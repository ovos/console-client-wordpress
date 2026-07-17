/**
 * ovos/console browser error client — no dependencies, no build.
 *
 *   <script src="https://console.example/js/console-client.js"></script>
 *   <script>
 *     ovosConsole.init({
 *       url: 'https://console.example',   // console instance base URL
 *       key: 'PUBLIC_JS_KEY',             // project js_key (public by design)
 *       logLevel: 4,                      // send priority <= logLevel
 *       release: '2026.06.28',            // optional deploy label (indexed)
 *       context: function () { return {userId: '...'}; }  // optional extras
 *     });
 *   </script>
 *
 * Further options (all optional): breadcrumbs (true), maxBreadcrumbs (20),
 * instrumentFetch (true), instrumentXhr (true), trace (true — send a W3C
 * traceparent header on the page's same-origin fetch/XHR calls, so a
 * backend sender that honors it (php-library's Console\Sender does)
 * reports the same trace id and frontend + backend errors of one request
 * correlate in the console; failed-request reports carry the id as
 * traceId, fetch/xhr breadcrumbs carry it too), traceOrigins (null —
 * extra origins to propagate to, string prefixes or RegExp; traceparent
 * is not CORS-safelisted, so a listed origin's server must allow the
 * header via Access-Control-Allow-Headers), reportResourceErrors
 * (false — report same-origin <script> load failures as errors),
 * reportAborts (false — AbortError rejections are deliberate cancels),
 * scrub (RegExp[] applied to urls/referrers on top of the default
 * sensitive-query-param redaction), snapshot (false — upload a masked
 * DOM snapshot with the first error per page load; snapshotPerPage,
 * snapshotMask: [selectors], snapshotMaxBytes tune it; snapshotStyles
 * (false) inlines the page's styling — stylesheets (same-origin via the
 * CSSOM, cross-origin via CORS fetch), expanded @imports, CSSOM-injected
 * and adopted rules — so the snapshot renders styled in the viewer,
 * snapshotStyleMaxBytes caps how much CSS that may add).
 *
 * Captures window "error" and "unhandledrejection" events; duplicates
 * within a page load are counted, not re-sent. Batches are flushed
 * after a short debounce and on page hide via sendBeacon (text/plain
 * keeps the request preflight-free — the server validates Origin).
 *
 * Every report carries diagnostics under `extra`: network/page state,
 * viewport, a per-page-load pageId, a breadcrumb trail (fetch/XHR,
 * clicks, navigation, console.error/warn, failed resources) and — for
 * failed fetch calls — the request itself with a stack captured at
 * call time, because a rejected fetch ("Load failed" / "Failed to
 * fetch") carries no stack of its own. Such reports also use the
 * failing request path as their `file`, so network errors group into
 * one issue per endpoint instead of one site-wide bucket.
 *
 * This file must never throw into the host page.
 *
 * Intentionally ES5 ("var", no arrow functions, no template literals) —
 * do not modernize: the script must PARSE in the oldest WebViews and
 * embedded browsers, because a SyntaxError would silence error reporting
 * exactly where the errors live. Newer runtime APIs are feature-detected
 * instead (sendBeacon, fetch), and every handler swallows its own
 * exceptions. The "latest browsers only" rule covers the console UI, not
 * this embed.
 */
(function () {
	'use strict';
	
	var config = null;
	var queue = [];
	var seen = {};        // dedup key -> report (count increments)
	var uniqueSent = 0;
	var flushTimer = null;
	var pageId = '';      // random per page load — correlates sibling reports
	var breadcrumbs = []; // ring buffer, oldest first
	var loadStart = Date.now();
	
	// values of sensitive-looking query params -> [redacted]
	var SCRUB_PARAMS = /([?&#][^=&#]*(?:token|key|password|passwd|auth|session|secret|email)[^=&#]*=)[^&#]*/gi;
	// token-shaped PATH segments (JWT, long hex/base64url, uuid) -> [redacted];
	// query params live in SCRUB_PARAMS, but secrets hide in paths too
	// (/reset-password/<jwt>, /invite/<token>)
	var SCRUB_PATH = /(\/)(?:eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[A-Za-z0-9_-]{24,})(?=[/?#]|$)/g;
	
	var DEFAULTS = {
		url: '',
		key: '',
		logLevel: 4,
		maxErrors: 10,      // unique errors per page load
		flushDelay: 2000,
		maxBytes: 60000,    // sendBeacon payload cap
		ignore: [/^Script error\.?$/, /^ResizeObserver loop/],
		release: '',        // deploy label (git sha, version) — indexed server-side
		context: null,
		breadcrumbs: true,
		maxBreadcrumbs: 20,
		instrumentFetch: true,
		instrumentXhr: true,
		trace: true,        // traceparent on same-origin fetch/XHR (backend correlation)
		traceOrigins: null, // extra origins to trace (string prefixes or RegExp)
		reportResourceErrors: false,
		reportAborts: false,
		scrub: null,
		snapshot: false,        // masked DOM snapshot on error (replay-lite)
		snapshotPerPage: 1,
		snapshotMask: null,     // extra CSS selectors to mask (PII areas)
		snapshotMaxBytes: 512000,
		snapshotStyles: false,  // inline same-origin stylesheets into the snapshot
		snapshotStyleMaxBytes: 400000, // cumulative raw-CSS budget for inlining
	};
	var snapshotsSent = 0;
	
	function init(options) {
		try {
			config = {};
			for (var key in DEFAULTS) {
				config[key] = options && options[key] !== undefined ? options[key] : DEFAULTS[key];
			}
			config.url = String(config.url || '').replace(/\/+$/, '');
			if (!config.url || !config.key) {
				config = null;
				return;
			}
			
			pageId = randomId();
			
			window.addEventListener('error', onError);
			window.addEventListener('unhandledrejection', onRejection);
			window.addEventListener('pagehide', flush);
			document.addEventListener('visibilitychange', function () {
				if (document.visibilityState === 'hidden') {
					flush();
				}
			});
			
			// resource load failures do not bubble — capture phase only
			window.addEventListener('error', onResourceError, true);
			
			if (config.instrumentFetch) {
				instrumentFetch();
			}
			if (config.instrumentXhr) {
				instrumentXhr();
			}
			if (config.breadcrumbs) {
				instrumentClicks();
				instrumentNav();
				instrumentConsole();
			}
		} catch (ignored) { /* never break the host page */ }
	}
	
	function onError(event) {
		try {
			if (!(event instanceof ErrorEvent)) {
				return; // resource load errors are handled by onResourceError
			}
			if (isExtensionUrl(event.filename)) {
				return;
			}
			report({
				message: event.message,
				name: event.error && event.error.name || 'Error',
				stack: event.error && event.error.stack || '',
				file: event.filename || '',
				line: event.lineno || 0,
				col: event.colno || 0,
				priority: 3,
				cause: causeChain(event.error),
			});
		} catch (ignored) {}
	}
	
	function onRejection(event) {
		try {
			var reason = event.reason;
			
			// aborted fetches are deliberate cancellations, not errors
			if (!config.reportAborts && reason && reason.name === 'AbortError') {
				return;
			}
			
			var request = reason && reason.__ovosConsoleRequest;
			
			report({
				message: stringifyReason(reason),
				name: reason && reason.name || 'UnhandledRejection',
				stack: reason && reason.stack || '',
				// a tagged reason IS a failed fetch (only the wrapper's rejection
				// path tags) — group by the failing endpoint instead of collapsing
				// into one site-wide issue; Safari has no stack here, Chrome's
				// points at internals, neither groups usefully
				file: request ? urlPath(request.url) : '',
				line: 0,
				col: 0,
				priority: 3,
				request: request,
				cause: causeChain(reason),
			});
		} catch (ignored) {}
	}
	
	function onResourceError(event) {
		try {
			var target = event.target;
			if (!target || target === window || !target.tagName || event instanceof ErrorEvent) {
				return; // JS errors go through onError
			}
			
			var tag = target.tagName.toLowerCase();
			var url = String(target.src || target.href || '');
			if (!url || isExtensionUrl(url)) {
				return;
			}
			
			// often the root cause of the TypeErrors that follow
			crumb('resource', {tag: tag, url: scrub(url, 200)});
			
			// a broken same-origin script is a deploy problem — loud, if enabled
			if (config.reportResourceErrors && tag === 'script'
				&& window.location.origin && url.indexOf(window.location.origin) === 0) {
				report({
					message: 'script failed to load: ' + scrub(url, 300),
					name: 'ResourceError',
					stack: '',
					file: urlPath(url),
					line: 0,
					col: 0,
					priority: 3,
				});
			}
		} catch (ignored) {}
	}
	
	function captureException(error, extra, priority) {
		try {
			var request = error && error.__ovosConsoleRequest;
			report({
				message: error && error.message || String(error),
				name: error && error.name || 'Error',
				stack: error && error.stack || '',
				file: request ? urlPath(request.url) : '',
				line: 0,
				col: 0,
				priority: priority === undefined ? 3 : priority,
				extra: extra,
				request: request,
				cause: causeChain(error),
			});
		} catch (ignored) {}
	}
	
	function captureMessage(message, priority, extra) {
		try {
			report({
				message: String(message),
				name: 'Message',
				stack: '',
				file: '',
				line: 0,
				col: 0,
				priority: priority === undefined ? 5 : priority,
				extra: extra,
			});
		} catch (ignored) {}
	}
	
	function report(error) {
		if (!config || error.priority > config.logLevel) {
			return;
		}
		if (isIgnored(error.message)) {
			return;
		}
		
		var key = [error.message, error.file, error.line, error.col].join('|');
		
		if (seen[key]) {
			seen[key].count++; // error loops send once, not 60/s
			return;
		}
		
		if (uniqueSent >= config.maxErrors) {
			return; // page error budget exhausted
		}
		uniqueSent++;
		
		var entry = {
			message: truncate(error.message, 2000),
			name: truncate(error.name, 100),
			stack: truncate(error.stack, 8000),
			file: truncate(error.file, 500),
			line: error.line,
			col: error.col,
			priority: error.priority,
			count: 1,
			url: scrub(location.href, 500),
			timestamp: Date.now(),
			extra: buildExtra(error),
		};
		
		if (config.release) {
			entry.release = truncate(config.release, 64);
		}
		// a failed request's trace id — indexed server-side, links the report
		// to the backend errors of the same request
		if (error.request && error.request.traceId) {
			entry.traceId = error.request.traceId;
		}
		
		shrink(entry);
		
		seen[key] = entry;
		queue.push(entry);
		// stamps entry.extra.snapshotId only if a snapshot is actually
		// uploaded — so a dropped capture leaves no dangling VIEW button
		// and does not burn the per-page budget
		maybeSnapshot(entry);
		schedule();
	}
	
	/** per-report diagnostics + user context + breadcrumbs + request */
	function buildExtra(error) {
		var merged = diagnostics();
		
		try {
			if (typeof config.context === 'function') {
				var context = config.context();
				for (var key in context) {
					merged[key] = context[key];
				}
			}
		} catch (ignored) {}
		
		if (error.cause) {
			merged.cause = error.cause;
		}
		if (error.request) {
			merged.request = error.request;
		}
		if (config.breadcrumbs && breadcrumbs.length) {
			merged.breadcrumbs = breadcrumbs.slice();
		}
		
		if (error.extra) {
			for (var extraKey in error.extra) {
				merged[extraKey] = error.extra[extraKey];
			}
		}
		
		return merged;
	}
	
	function diagnostics() {
		var extra = {pageId: pageId};
		try {
			extra.online = navigator.onLine !== false;
			if (navigator.connection && navigator.connection.effectiveType) {
				extra.connection = navigator.connection.effectiveType;
			}
			extra.visibility = document.visibilityState || '';
			extra.sinceLoadMs = sinceLoad();
			extra.viewport = window.innerWidth + 'x' + window.innerHeight;
			extra.dpr = window.devicePixelRatio || 1;
			if (window.screen) {
				extra.screen = screen.width + 'x' + screen.height;
			}
			extra.lang = navigator.language || '';
			if (document.referrer) {
				extra.referrer = scrub(document.referrer, 300);
			}
		} catch (ignored) {}
		return extra;
	}
	
	/** flattened error.cause chain, bounded */
	function causeChain(error) {
		var parts = [];
		try {
			var cause = error && error.cause;
			var depth = 0;
			while (cause && depth < 3) {
				if (cause instanceof Error) {
					parts.push((cause.name || 'Error') + ': ' + truncate(cause.message || '', 200));
					cause = cause.cause;
				} else {
					parts.push(truncate(stringifyReason(cause), 200));
					break;
				}
				depth++;
			}
		} catch (ignored) {}
		return parts.join(' <- ');
	}
	
	function stringifyReason(reason) {
		if (reason === null || reason === undefined) {
			return 'unhandled rejection: ' + String(reason);
		}
		if (typeof reason === 'string') {
			return reason;
		}
		// an Error (empty message included) keeps its class as the label —
		// never JSON.stringify it: Error fields are non-enumerable -> '{}'.
		// duck-type, not instanceof, so a cross-realm error (iframe, worker,
		// wrapped lib) is still recognised
		if (reason instanceof Error
			|| (typeof reason.stack === 'string' && typeof reason.name === 'string')) {
			return reason.message
				? (reason.name || 'Error') + ': ' + reason.message
				: (reason.name || 'Error');
		}
		if (typeof reason.message === 'string' && reason.message) {
			return reason.message;
		}
		// a plain object: report its shape (keys), never its values — a
		// rejection payload routinely carries tokens/PII, and the message
		// is transmitted and indexed unscrubbed
		try {
			var keys = Object.keys(reason).slice(0, 10).join(', ');
			return keys ? 'rejected object {' + truncate(keys, 200) + '}' : 'rejected object';
		} catch (ignored) {
			return 'rejected object';
		}
	}
	
	function crumb(type, data) {
		try {
			if (!config || !config.breadcrumbs) {
				return null;
			}
			
			var entry = {t: sinceLoad(), type: type};
			for (var key in data) {
				if (data[key] !== undefined && data[key] !== '') {
					entry[key] = data[key];
				}
			}
			
			breadcrumbs.push(entry);
			if (breadcrumbs.length > config.maxBreadcrumbs) {
				breadcrumbs.shift();
			}
			return entry;
		} catch (ignored) {
			return null;
		}
	}
	
	function instrumentClicks() {
		document.addEventListener('click', function (event) {
			try {
				var target = event.target;
				if (target && target.nodeType === 3) {
					target = target.parentNode; // text node
				}
				if (target && target.tagName) {
					crumb('click', {sel: selectorFor(target)});
				}
			} catch (ignored) {}
		}, true);
	}
	
	function instrumentNav() {
		try {
			var record = function () {
				crumb('nav', {url: scrub(location.href, 200)});
			};
			window.addEventListener('popstate', record);
			window.addEventListener('hashchange', record);
			
			if (window.history && history.pushState) {
				var push = history.pushState;
				history.pushState = function () {
					var result = push.apply(this, arguments);
					record();
					return result;
				};
				var replace = history.replaceState;
				history.replaceState = function () {
					var result = replace.apply(this, arguments);
					record();
					return result;
				};
			}
		} catch (ignored) {}
	}
	
	function instrumentConsole() {
		try {
			var levels = ['error', 'warn'];
			for (var i = 0; i < levels.length; i++) {
				(function (level) {
					var original = console[level];
					if (!original || original.__ovosConsole) {
						return;
					}
					console[level] = function () {
						try {
							crumb('console', {level: level, msg: truncate(formatArgs(arguments), 200)});
						} catch (ignored) {}
						return original.apply(console, arguments);
					};
					console[level].__ovosConsole = true;
				})(levels[i]);
			}
		} catch (ignored) {}
	}
	
	function formatArgs(args) {
		var parts = [];
		for (var i = 0; i < args.length && i < 3; i++) {
			var value = args[i];
			if (typeof value === 'string') {
				parts.push(value);
			} else if (value instanceof Error) {
				parts.push(value.name + ': ' + value.message);
			} else {
				try {
					parts.push(JSON.stringify(value));
				} catch (circular) {
					parts.push(String(value));
				}
			}
		}
		return parts.join(' ');
	}
	
	/** short ancestor-path selector — never element text or HTML */
	function selectorFor(element) {
		var parts = [];
		var node = element;
		var depth = 0;
		while (node && node.tagName && depth < 3) {
			var part = node.tagName.toLowerCase();
			if (node.id) {
				parts.unshift(part + '#' + node.id);
				break; // an id anchors the path well enough
			}
			if (typeof node.className === 'string' && node.className) {
				var classes = node.className.split(/\s+/).filter(Boolean).slice(0, 2);
				if (classes.length) {
					part += '.' + classes.join('.');
				}
			}
			parts.unshift(part);
			node = node.parentNode;
			depth++;
		}
		return truncate(parts.join(' > '), 200);
	}
	
	function instrumentFetch() {
		try {
			if (!window.fetch || window.fetch.__ovosConsole) {
				return;
			}
			
			var original = window.fetch;
			var wrapped = function () {
				var args = arguments;
				var info = null;
				try {
					var input = args[0];
					info = {
						method: String((args[1] && args[1].method) || (input && input.method) || 'GET').toUpperCase(),
						url: String((input && input.url) || input || ''),
						// a rejected fetch has no stack — capture the call site now
						stack: callSite(),
						started: sinceLoad(),
					};
					// never trace our own beacon traffic
					if (config && config.url && info.url.indexOf(config.url) === 0) {
						info = null;
					}
				} catch (ignored) {}
				
				// backend correlation: hand the request a traceparent the
				// server-side sender picks up; any hiccup while patching the
				// args falls back to the untouched call
				var patched = null;
				try {
					if (info && config && config.trace && traceEligible(info.url)) {
						patched = injectTrace(args[0], args[1]);
						if (patched) {
							info.traceId = patched.traceId;
						}
					}
				} catch (ignored) {
					patched = null;
				}
				
				var result = patched
					? original.call(this, patched.input, patched.init)
					: original.apply(this, args);
				if (!info || !result || !result.then) {
					return result;
				}
				
				return result.then(function (response) {
					finishRequest(info, response && response.status || 0);
					return response;
				}, function (error) {
					finishRequest(info, 0);
					tagError(error, info);
					throw error;
				});
			};
			wrapped.__ovosConsole = true;
			window.fetch = wrapped;
		} catch (ignored) {}
	}
	
	function instrumentXhr() {
		try {
			if (!window.XMLHttpRequest) {
				return;
			}
			var proto = XMLHttpRequest.prototype;
			if (!proto.open || proto.open.__ovosConsole) {
				return;
			}
			
			var open = proto.open;
			var send = proto.send;
			var setHeader = proto.setRequestHeader;
			
			proto.open = function (method, url) {
				try {
					this.__ovosConsole = {
						method: String(method || 'GET').toUpperCase(),
						url: String(url || ''),
					};
				} catch (ignored) {}
				return open.apply(this, arguments);
			};
			proto.open.__ovosConsole = true;
			
			// remember an app-set traceparent: XHR COMBINES repeated headers,
			// so injecting a second value would corrupt the app's own tracing
			proto.setRequestHeader = function (name, value) {
				try {
					if (this.__ovosConsole && String(name).toLowerCase() === 'traceparent') {
						this.__ovosConsole.traceparent = String(value || '');
					}
				} catch (ignored) {}
				return setHeader.apply(this, arguments);
			};
			proto.setRequestHeader.__ovosConsole = true;
			
			proto.send = function () {
				var xhr = this;
				try {
					var info = xhr.__ovosConsole;
					if (info && !(config && config.url && info.url.indexOf(config.url) === 0)) {
						info.started = sinceLoad();
						if (config.trace && traceEligible(info.url)) {
							if (info.traceparent) {
								// the app's own tracer decided — reuse its id
								var parsed = /^[0-9a-f]{2}-([0-9a-f]{32})-/.exec(info.traceparent.toLowerCase());
								info.traceId = parsed ? parsed[1] : undefined;
							} else {
								try {
									var traceId = randomHex(32);
									setHeader.call(xhr, 'traceparent',
										'00-' + traceId + '-' + randomHex(16) + '-00');
									info.traceId = traceId;
								} catch (ignored) {}
							}
						}
						xhr.addEventListener('loadend', function () {
							var data = {
								method: info.method,
								url: scrub(info.url, 200),
								status: xhr.status,
								durMs: sinceLoad() - info.started,
							};
							if (info.traceId) {
								data.traceId = info.traceId;
							}
							crumb('xhr', data);
						});
					}
				} catch (ignored) {}
				return send.apply(this, arguments);
			};
		} catch (ignored) {}
	}
	
	function finishRequest(info, status) {
		try {
			info.status = status;
			info.durationMs = sinceLoad() - info.started;
			
			// method/url/status only — never the response body: it routinely
			// carries tokens/PII and scrub() only redacts url query params
			var data = {
				method: info.method,
				url: scrub(info.url, 200),
				status: status,
				durMs: info.durationMs,
			};
			if (info.traceId) {
				data.traceId = info.traceId;
			}
			crumb('fetch', data);
		} catch (ignored) {}
	}
	
	/** attach the request to the error object the rejection will surface */
	function tagError(error, info) {
		try {
			if (error && typeof error === 'object') {
				error.__ovosConsoleRequest = {
					method: info.method,
					url: scrub(info.url, 500),
					status: info.status || 0,
					durationMs: info.durationMs || 0,
					callStack: info.stack,
				};
				if (info.traceId) {
					error.__ovosConsoleRequest.traceId = info.traceId;
				}
			}
		} catch (ignored) {}
	}
	
	/** call-site stack, minus this helper and the wrapper frame */
	function callSite() {
		try {
			var lines = String(new Error().stack || '').split('\n');
			if (lines[0] && /^\s*Error/.test(lines[0])) {
				lines = lines.slice(1); // V8 prefixes the message line
			}
			return truncate(lines.slice(2).join('\n'), 2000);
		} catch (ignored) {
			return '';
		}
	}
	
	/**
	 * Capture a DOM snapshot off the error path and, once it is actually
	 * uploaded, stamp its id onto `entry` so the error links to it. Reserves
	 * the per-page budget up front but returns it if the capture is dropped,
	 * so an oversized page does not silently consume the one allowed snapshot.
	 */
	function maybeSnapshot(entry) {
		try {
			if (!config.snapshot || snapshotsSent >= config.snapshotPerPage
				|| !document.documentElement || !document.documentElement.cloneNode
				|| !window.fetch || !window.Blob) {
				return;
			}
			snapshotsSent++;
			
			var id = (randomId() + randomId()).slice(0, 16);
			// serialization must never slow the error path — defer it
			setTimeout(function () {
				captureSnapshot(id, entry);
			}, 0);
		} catch (ignored) {}
	}
	
	/** hand the budget back so a later error on this page can still snapshot */
	function dropSnapshot() {
		if (snapshotsSent > 0) {
			snapshotsSent--;
		}
	}
	
	function captureSnapshot(id, entry) {
		try {
			var clone = document.documentElement.cloneNode(true);
			sanitizeSnapshot(clone);
			
			// opt-in: fold the page's styling into the clone so the snapshot
			// renders styled in the sandboxed viewer (which cannot pull the
			// site's cross-origin/hotlink-gated/rotated assets). The remote
			// pass is async (CORS fetch of unreadable sheets) — the capture
			// continues in its callback.
			if (config.snapshotStyles) {
				var used = inlineStylesheets(clone);
				inlineRemoteStylesheets(clone, used, function () {
					finishSnapshot(id, entry, clone);
				});
				return;
			}
			
			finishSnapshot(id, entry, clone);
		} catch (ignored) {
			dropSnapshot();
		}
	}
	
	function finishSnapshot(id, entry, clone) {
		try {
			var htmlText = '<!doctype html>\n' + clone.outerHTML;
			if (htmlText.length > 3000000) {
				dropSnapshot(); // pathological page — not worth the upload
				return;
			}
			uploadSnapshot(id, htmlText, entry);
		} catch (ignored) {
			dropSnapshot();
		}
	}
	
	/**
	 * Masks the clone in place: no scripts, no inline handlers, no
	 * server-rendered form values, PII areas blanked. Typed input values
	 * never reach the clone — cloneNode copies attributes, not live state.
	 */
	function sanitizeSnapshot(clone) {
		var i;
		
		var risky = clone.querySelectorAll('script, noscript, iframe');
		for (i = risky.length - 1; i >= 0; i--) {
			risky[i].parentNode.removeChild(risky[i]);
		}
		
		var all = clone.querySelectorAll('*');
		for (i = 0; i < all.length; i++) {
			var element = all[i];
			var attributes = element.attributes;
			for (var a = attributes.length - 1; a >= 0; a--) {
				if (attributes[a].name.indexOf('on') === 0) {
					element.removeAttribute(attributes[a].name);
				}
			}
			if (element.tagName === 'INPUT' && element.getAttribute('value')) {
				element.setAttribute('value', '***');
			} else if (element.tagName === 'TEXTAREA' && element.textContent) {
				element.textContent = '***';
			}
		}
		
		var selectors = ['[data-console-mask]'].concat(config.snapshotMask || []);
		for (i = 0; i < selectors.length; i++) {
			try {
				var masked = clone.querySelectorAll(selectors[i]);
				for (var m = 0; m < masked.length; m++) {
					masked[m].textContent = '***';
				}
			} catch (badSelector) {}
		}
		
		// resolve relative urls (images, and any stylesheet left as a <link>)
		// when viewed on the console host — pages carrying their own <base>
		// already resolve correctly and must not have it overridden
		try {
			var head = clone.querySelector('head');
			if (head && !clone.querySelector('base')) {
				var base = document.createElement('base');
				base.setAttribute('href', location.href.split('#')[0]);
				head.insertBefore(base, head.firstChild);
			}
		} catch (ignored) {}
	}
	
	/**
	 * Serialized cssRules of a live sheet, or null when unreadable/empty.
	 * Same-origin @import rules are expanded in place (the sandboxed viewer
	 * could not fetch them), their media condition preserved as an @media
	 * wrapper; layer()/supports() imports stay verbatim — expansion would
	 * lose their semantics. Depth-capped against import chains.
	 */
	function sheetCssText(sheet, depth) {
		var rules;
		try {
			rules = sheet.cssRules; // cross-origin -> SecurityError
		} catch (crossOrigin) {
			return null;
		}
		if (!rules || !rules.length) {
			return null;
		}
		var css = '';
		for (var r = 0; r < rules.length; r++) {
			var rule = rules[r];
			// CSSRule.IMPORT_RULE — plain imports only (no layer/supports)
			if (rule.type === 3 && rule.styleSheet && (depth || 0) < 4
				&& !rule.layerName && !rule.supportsText) {
				var imported = sheetCssText(rule.styleSheet, (depth || 0) + 1);
				if (imported !== null) {
					// inner urls resolve against the IMPORTED sheet's own base
					imported = absolutizeCssUrls(imported,
						rule.styleSheet.href || sheet.href || document.baseURI || location.href);
					var condition = rule.media && rule.media.mediaText;
					css += condition
						? '@media ' + condition + ' {\n' + imported + '}\n'
						: imported;
					continue;
				}
			}
			css += rule.cssText + '\n';
		}
		return css;
	}
	
	/** replace a clone <link> with an inline <style> (media kept) */
	function linkToStyle(link, css) {
		var style = document.createElement('style');
		var media = link.getAttribute('media');
		if (media) {
			style.setAttribute('media', media);
		}
		style.textContent = css;
		link.parentNode.replaceChild(style, link);
	}
	
	/**
	 * Makes the clone's styling self-contained (shared CSS budget, fully
	 * guarded — best-effort, never fails the capture). Returns the budget
	 * bytes used, so the async remote pass continues the same accounting.
	 *
	 * 1. <style> elements whose rules live only in the CSSOM — CSS-in-JS
	 *    libraries in production inject via insertRule(), leaving the node
	 *    EMPTY, so the clone would carry no styling at all — get their rules
	 *    re-serialized into text. Must run before step 3: it pairs live and
	 *    clone <style> lists by index, which folding <link>s would break.
	 * 2. document.adoptedStyleSheets (constructable sheets) have no DOM node
	 *    at all — serialized into one <style> appended at the end of body,
	 *    where they land in cascade order (after all document sheets).
	 * 3. Same-origin <link rel=stylesheet> are replaced with inline <style>
	 *    carrying the sheet's rules; a sheet whose cssRules throws (cross-
	 *    origin) stays a <link> for inlineRemoteStylesheets to try.
	 *
	 * url()/@import targets are absolutized against each sheet's own base:
	 * once inlined into the snapshot, a relative url() would otherwise
	 * resolve against the viewer's base and break.
	 */
	function inlineStylesheets(clone) {
		var inlined = 0;
		try {
			if (!window.URL) {
				return 0; // no absolute-url resolution — leave everything as is
			}
			
			var pageBase = document.baseURI || location.href;
			var budget = config.snapshotStyleMaxBytes;
			var i;
			var css;
			
			// 1. CSSOM-only <style> rules (live and clone lists are index-
			// aligned — the clone is a deep copy, nothing reordered yet)
			var liveStyles = document.querySelectorAll('style');
			var cloneStyles = clone.querySelectorAll('style');
			for (i = 0; i < liveStyles.length && i < cloneStyles.length; i++) {
				var node = cloneStyles[i];
				var liveSheet = liveStyles[i].sheet;
				// only rebuild nodes whose text carries no rules — a populated
				// <style> already travels with the clone as authored
				if (!liveSheet || liveSheet.disabled
					|| /\S/.test(node.textContent || '')) {
					continue;
				}
				css = sheetCssText(liveSheet, 0);
				if (css === null) {
					continue;
				}
				css = absolutizeCssUrls(css, pageBase);
				if (inlined + css.length > budget) {
					continue;
				}
				inlined += css.length;
				node.textContent = css;
			}
			
			// 2. adopted (constructable) sheets — no DOM node, cascade last
			try {
				var adoptedSheets = document.adoptedStyleSheets;
				var adopted = '';
				for (i = 0; adoptedSheets && i < adoptedSheets.length; i++) {
					if (adoptedSheets[i].disabled) {
						continue;
					}
					css = sheetCssText(adoptedSheets[i], 0);
					if (css !== null) {
						adopted += css;
					}
				}
				if (adopted) {
					adopted = absolutizeCssUrls(adopted, pageBase);
					if (inlined + adopted.length <= budget) {
						inlined += adopted.length;
						var tail = document.createElement('style');
						tail.textContent = adopted;
						(clone.querySelector('body') || clone).appendChild(tail);
					}
				}
			} catch (noAdopted) {}
			
			// 3. same-origin <link> stylesheets -> inline <style>
			var byHref = {};
			var sheets = document.styleSheets;
			for (var s = 0; s < sheets.length; s++) {
				if (sheets[s] && sheets[s].href) {
					byHref[sheets[s].href] = sheets[s];
				}
			}
			
			var links = clone.querySelectorAll('link[rel~="stylesheet"]');
			var done = {};
			
			for (i = 0; i < links.length; i++) {
				var link = links[i];
				var raw = link.getAttribute('href');
				if (!raw) {
					continue;
				}
				
				// hrefs resolve against the page's base (it may carry <base>)
				var href;
				try {
					href = new URL(raw, pageBase).href;
				} catch (badUrl) {
					continue;
				}
				
				var sheet = byHref[href];
				if (!sheet || sheet.disabled || done[href]) {
					continue; // a duplicate <link> must not double the CSS
				}
				
				css = sheetCssText(sheet, 0);
				if (css === null) {
					continue;
				}
				css = absolutizeCssUrls(css, href);
				
				if (inlined + css.length > budget) {
					continue; // over budget — leave this one as a <link>
				}
				inlined += css.length;
				done[href] = true;
				
				linkToStyle(link, css);
			}
		} catch (ignored) {}
		return inlined;
	}
	
	/**
	 * Second, async pass over the <link>s the CSSOM could not read: many
	 * CDNs serve CSS with open CORS, so a plain fetch (from HTTP cache —
	 * the page loaded these sheets moments ago) recovers e.g. Google Fonts
	 * or jsdelivr styles. Hard-capped at 1500ms so the snapshot upload —
	 * and the entry.extra.snapshotId stamp — stays inside the 2000ms report
	 * flush window; whatever has not settled stays a <link>. Links whose
	 * live sheet is readable or disabled are the sync pass's deliberate
	 * decisions (budget, alternate stylesheets) and are not second-guessed.
	 */
	function inlineRemoteStylesheets(clone, used, done) {
		var finished = false;
		var timer = null;
		var finish = function () {
			if (!finished) {
				finished = true;
				if (timer) {
					clearTimeout(timer);
				}
				done();
			}
		};
		
		try {
			if (!window.URL || !window.fetch || !window.Promise) {
				finish();
				return;
			}
			
			var links = clone.querySelectorAll('link[rel~="stylesheet"]');
			if (!links.length) {
				finish();
				return;
			}
			
			var pageBase = document.baseURI || location.href;
			var budget = config.snapshotStyleMaxBytes;
			var pendingCount = links.length;
			var fetched = {};
			var settle = function () {
				if (--pendingCount === 0) {
					finish();
				}
			};
			
			var byHref = {};
			var sheets = document.styleSheets;
			for (var s = 0; s < sheets.length; s++) {
				if (sheets[s] && sheets[s].href) {
					byHref[sheets[s].href] = sheets[s];
				}
			}
			
			timer = setTimeout(finish, 1500);
			
			var fetchOne = function (link) {
				var href;
				try {
					href = new URL(link.getAttribute('href') || '', pageBase).href;
				} catch (badUrl) {
					settle();
					return;
				}
				if (!/^https?:/i.test(href) || fetched[href]) {
					settle();
					return;
				}
				
				var live = byHref[href];
				if (live) {
					if (live.disabled) {
						settle();
						return;
					}
					var readable = true;
					try {
						void live.cssRules;
					} catch (crossOrigin) {
						readable = false;
					}
					if (readable) {
						settle(); // sync pass owned this sheet's decision
						return;
					}
				}
				fetched[href] = true;
				
				fetch(href, {mode: 'cors', cache: 'force-cache'}).then(function (res) {
					if (!res.ok
						|| (res.headers.get('content-type') || '').indexOf('css') === -1) {
						throw 0;
					}
					return res.text().then(function (text) {
						if (finished) {
							return; // too late — the snapshot already uploaded
						}
						var css = absolutizeCssUrls(text, res.url || href);
						if (used + css.length <= budget) {
							used += css.length;
							linkToStyle(link, css);
						}
						settle();
					});
				}).catch(settle);
			};
			
			for (var i = 0; i < links.length; i++) {
				fetchOne(links[i]);
			}
		} catch (ignored) {
			finish();
		}
	}
	
	/** true when the target needs no rewrite (already absolute or inline) */
	function isAbsoluteCssTarget(target) {
		return !target || /^(data:|blob:|https?:|\/\/|#)/i.test(target);
	}
	
	/**
	 * Rewrite relative url() and @import "…" targets in cssText to absolute
	 * (the url() form of @import is covered by the url() pass)
	 */
	function absolutizeCssUrls(css, base) {
		return css
			.replace(/url\(\s*(['"]?)([^'")]+)\1\s*\)/g, function (whole, quote, path) {
				var target = path.replace(/^\s+|\s+$/g, '');
				if (isAbsoluteCssTarget(target)) {
					return whole;
				}
				try {
					return 'url(' + quote + new URL(target, base).href + quote + ')';
				} catch (badUrl) {
					return whole;
				}
			})
			.replace(/(@import\s+)(['"])([^'"]+)\2/g, function (whole, lead, quote, path) {
				var target = path.replace(/^\s+|\s+$/g, '');
				if (isAbsoluteCssTarget(target)) {
					return whole;
				}
				try {
					return lead + quote + new URL(target, base).href + quote;
				} catch (badUrl) {
					return whole;
				}
			});
	}
	
	/**
	 * Gzip via CompressionStream where available, then POST as
	 * application/octet-stream — the page is alive at error time, so the
	 * CORS preflight this triggers is fine (snapshots never ride the beacon).
	 */
	function uploadSnapshot(id, htmlText, entry) {
		try {
			var endpoint = config.url + '/api/v1/ingest/snapshot/'
				+ encodeURIComponent(config.key) + '?id=' + id;
			var raw = new Blob([htmlText]);
			
			var post = function (blob, enc) {
				if (blob.size > config.snapshotMaxBytes) {
					dropSnapshot(); // still oversized after compression — give up
					return;
				}
				// link the error to the snapshot only now that we are sending
				// it — a report never advertises a snapshot that was dropped
				entry.extra.snapshotId = id;
				fetch(endpoint + (enc ? '&enc=' + enc : ''), {
					method: 'POST',
					body: blob,
					headers: {'Content-Type': 'application/octet-stream'},
				}).catch(function () {});
			};
			
			if (window.CompressionStream && window.Response && raw.stream) {
				new Response(raw.stream().pipeThrough(new CompressionStream('gzip'))).blob()
					.then(function (gzipped) {
						post(gzipped, 'gzip');
					}, function () {
						post(raw, '');
					});
			} else {
				post(raw, '');
			}
		} catch (ignored) {}
	}
	
	function isIgnored(message) {
		for (var i = 0; i < config.ignore.length; i++) {
			var rule = config.ignore[i];
			if (rule instanceof RegExp ? rule.test(message) : message.indexOf(rule) !== -1) {
				return true;
			}
		}
		return false;
	}
	
	function isExtensionUrl(url) {
		return /^(chrome|moz|safari)-extension:/.test(url || '');
	}
	
	/** truncate + redact sensitive query-param values, token-shaped path
		segments, and any caller-supplied patterns */
	function scrub(value, max) {
		value = truncate(value, max || 500);
		try {
			value = value.replace(SCRUB_PARAMS, '$1[redacted]');
			value = value.replace(SCRUB_PATH, '$1[redacted]');
			if (config && config.scrub) {
				for (var i = 0; i < config.scrub.length; i++) {
					// force a global match — a caller's plain /re/ would else
					// redact only the first occurrence
					value = value.replace(globalize(config.scrub[i]), '[redacted]');
				}
			}
		} catch (ignored) {}
		return value;
	}
	
	/** a RegExp with the global flag guaranteed (cached on the source regex) */
	function globalize(pattern) {
		if (!(pattern instanceof RegExp) || pattern.global) {
			return pattern;
		}
		if (!pattern.__ovosGlobal) {
			pattern.__ovosGlobal = new RegExp(pattern.source, pattern.flags + 'g');
		}
		return pattern.__ovosGlobal;
	}
	
	/** host-aware path of a request url — the grouping key for failed calls.
		Token-shaped segments are redacted: they leak, and they also splinter
		grouping (every /orders/<uuid> would be its own issue). */
	function urlPath(url) {
		try {
			var a = document.createElement('a');
			a.href = url;
			// keep the host when it is not ours, so third-party endpoints stay apart
			var path = (a.host && a.host !== location.host ? a.host : '') + a.pathname;
			return path.replace(SCRUB_PATH, '$1[redacted]');
		} catch (ignored) {
			return truncate(String(url).split('?')[0], 200);
		}
	}
	
	function sinceLoad() {
		try {
			if (window.performance && performance.now) {
				return Math.round(performance.now());
			}
		} catch (ignored) {}
		return Date.now() - loadStart;
	}
	
	function randomId() {
		var id = '';
		while (id.length < 8) {
			id += Math.random().toString(16).slice(2);
		}
		return id.slice(0, 8);
	}
	
	/** length hex chars; all-zero is forbidden for W3C trace ids */
	function randomHex(length) {
		var hex = '';
		try {
			if (window.crypto && crypto.getRandomValues) {
				var bytes = new Uint8Array(length / 2);
				crypto.getRandomValues(bytes);
				for (var i = 0; i < bytes.length; i++) {
					hex += (bytes[i] + 256).toString(16).slice(1);
				}
			}
		} catch (ignored) {
			hex = '';
		}
		while (hex.length < length) {
			hex += Math.random().toString(16).slice(2);
		}
		hex = hex.slice(0, length);
		return /[1-9a-f]/.test(hex) ? hex : '1' + hex.slice(1);
	}
	
	/** should this request carry a traceparent? Same-origin always (no CORS
		involved); other hosts only when listed in traceOrigins, because the
		header is not CORS-safelisted — it would add preflights the target
		must answer with Access-Control-Allow-Headers: traceparent */
	function traceEligible(url) {
		try {
			var a = document.createElement('a');
			a.href = url;
			// the scheme must match too: http->https on the same host is
			// cross-origin, and the header would force a preflight there
			if (!a.host || (a.host === location.host
				&& (!a.protocol || a.protocol === location.protocol))) {
				return true;
			}
			var origins = config.traceOrigins;
			for (var i = 0; origins && i < origins.length; i++) {
				var origin = origins[i];
				if (origin instanceof RegExp ? origin.test(url) : String(url).indexOf(origin) === 0) {
					return true;
				}
			}
		} catch (ignored) {}
		return false;
	}
	
	/** fetch args with a traceparent header added — {input, init, traceId},
		or null when the header cannot be placed safely (the caller then
		sends the request untouched). An app-set traceparent (its own OTEL
		SDK) is honored: reused for the report, never overwritten. */
	function injectTrace(input, init) {
		var existing = headerValue(init && init.headers, 'traceparent')
			|| (input && typeof input === 'object' && input.headers
				&& typeof input.headers.get === 'function'
				&& input.headers.get('traceparent')) || '';
		if (existing) {
			var parsed = /^[0-9a-f]{2}-([0-9a-f]{32})-/.exec(String(existing).toLowerCase());
			return parsed ? {input: input, init: init, traceId: parsed[1]} : null;
		}
		
		var traceId = randomHex(32);
		var header = '00-' + traceId + '-' + randomHex(16) + '-00';
		
		var patched = {};
		for (var key in init || {}) {
			patched[key] = init[key];
		}
		
		if (init && init.headers) {
			patched.headers = withHeader(init.headers, header);
		} else if (input && typeof input === 'object' && input.headers) {
			// init.headers REPLACES a Request's own headers wholesale —
			// copy them all before adding ours
			patched.headers = withHeader(input.headers, header);
		} else {
			patched.headers = {traceparent: header};
		}
		
		return patched.headers ? {input: input, init: patched, traceId: traceId} : null;
	}
	
	/** copy of a HeadersInit with traceparent set — Headers instance, entry
		array or plain object; null for shapes we do not understand */
	function withHeader(headers, value) {
		try {
			if (typeof Headers === 'function' && headers instanceof Headers) {
				var copy = new Headers(headers);
				copy.set('traceparent', value);
				return copy;
			}
			if (Object.prototype.toString.call(headers) === '[object Array]') {
				return headers.concat([['traceparent', value]]);
			}
			if (headers && typeof headers === 'object' && typeof headers.get !== 'function') {
				var plain = {};
				for (var key in headers) {
					plain[key] = headers[key];
				}
				plain.traceparent = value;
				return plain;
			}
		} catch (ignored) {}
		return null;
	}
	
	/** case-insensitive header lookup across the HeadersInit shapes */
	function headerValue(headers, name) {
		try {
			if (!headers) {
				return '';
			}
			if (typeof headers.get === 'function') {
				return String(headers.get(name) || '');
			}
			if (Object.prototype.toString.call(headers) === '[object Array]') {
				for (var i = 0; i < headers.length; i++) {
					if (String(headers[i] && headers[i][0]).toLowerCase() === name) {
						return String(headers[i][1] || '');
					}
				}
				return '';
			}
			for (var key in headers) {
				if (key.toLowerCase() === name) {
					return String(headers[key] || '');
				}
			}
		} catch (ignored) {}
		return '';
	}
	
	/** drop breadcrumbs before the batch trim ever has to drop whole reports */
	function shrink(entry) {
		try {
			if (JSON.stringify(entry).length > 10000 && entry.extra.breadcrumbs) {
				entry.extra.breadcrumbs = entry.extra.breadcrumbs.slice(-5);
				if (JSON.stringify(entry).length > 10000) {
					delete entry.extra.breadcrumbs;
				}
			}
		} catch (ignored) {}
	}
	
	function truncate(value, max) {
		value = String(value == null ? '' : value);
		return value.length > max ? value.slice(0, max) : value;
	}
	
	function schedule() {
		if (!flushTimer) {
			flushTimer = setTimeout(flush, config.flushDelay);
		}
	}
	
	function flush() {
		try {
			clearTimeout(flushTimer);
			flushTimer = null;
			
			if (!config || queue.length === 0) {
				return;
			}
			
			var batch = queue;
			queue = [];
			
			var body = JSON.stringify(batch);
			while (body.length > config.maxBytes && batch.length > 1) {
				batch.pop();
				body = JSON.stringify(batch);
			}
			
			var endpoint = config.url + '/api/v1/ingest/js/' + encodeURIComponent(config.key);
			
			// text/plain keeps it a "simple" request: no CORS preflight,
			// sendBeacon-compatible; the server decodes the body regardless
			if (navigator.sendBeacon
				&& navigator.sendBeacon(endpoint, new Blob([body], {type: 'text/plain'}))) {
				return;
			}
			
			if (window.fetch) {
				fetch(endpoint, {
					method: 'POST',
					body: body,
					headers: {'Content-Type': 'text/plain'},
					keepalive: true,
				}).catch(function () {});
			}
		} catch (ignored) {}
	}
	
	window.ovosConsole = {
		init: init,
		captureException: captureException,
		captureMessage: captureMessage,
		flush: flush,
	};
})();
