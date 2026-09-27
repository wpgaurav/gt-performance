(() => {
	"use strict";

	const setupCachePresets = () => {
		const container = document.querySelector("[data-gtp-cache-presets]");
		if (!container) {
			return;
		}

		const fields = {
			freshTtl: document.querySelector("#gtp-cache-fresh_ttl"),
			staleTtl: document.querySelector("#gtp-cache-stale_ttl"),
			staleIfError: document.querySelector("#gtp-cache-stale_if_error"),
			browserTtl: document.querySelector("#gtp-cache-browser_ttl"),
		};
		const buttons = Array.from(container.querySelectorAll("[data-gtp-cache-preset]"));
		const status = container.querySelector("[data-gtp-cache-preset-status]");

		if (Object.values(fields).some((field) => !field)) {
			return;
		}

		const syncSelection = () => {
			buttons.forEach((button) => {
				const selected =
					fields.freshTtl.value === button.dataset.freshTtl &&
					fields.staleTtl.value === button.dataset.staleTtl &&
					fields.staleIfError.value === button.dataset.staleIfError &&
					fields.browserTtl.value === button.dataset.browserTtl;
				button.setAttribute("aria-pressed", selected ? "true" : "false");
			});
		};

		buttons.forEach((button) => {
			button.addEventListener("click", () => {
				fields.freshTtl.value = button.dataset.freshTtl;
				fields.staleTtl.value = button.dataset.staleTtl;
				fields.staleIfError.value = button.dataset.staleIfError;
				fields.browserTtl.value = button.dataset.browserTtl;
				syncSelection();
				status.textContent = `${button.querySelector("strong").textContent} applied. Save changes to make it active.`;
			});
		});

		Object.values(fields).forEach((field) => {
			field.addEventListener("input", () => {
				syncSelection();
				status.textContent = "";
			});
		});

		syncSelection();
	};

	const setupWordPressPresets = () => {
		const container = document.querySelector("[data-gtp-wordpress-presets]");
		if (!container) {
			return;
		}

		const toggleKeys = [
			"disable_emojis",
			"disable_dashicons",
			"disable_embeds",
			"disable_xmlrpc",
			"remove_rsd_link",
			"remove_jquery_migrate",
			"hide_wp_version",
			"remove_shortlink",
			"disable_rss_feeds",
			"disable_secondary_feeds",
			"remove_feed_links",
			"remove_secondary_feed_links",
			"disable_self_pingbacks",
			"remove_rest_api_links",
			"disable_google_maps",
			"disable_password_strength_meter",
			"remove_comment_urls",
			"blank_favicon",
			"remove_global_styles",
			"separate_block_styles",
		];
		const siteBaseline = new Set([
			"disable_emojis",
			"disable_dashicons",
			"disable_embeds",
			"disable_xmlrpc",
			"remove_rsd_link",
			"remove_jquery_migrate",
			"hide_wp_version",
			"remove_shortlink",
			"disable_secondary_feeds",
			"remove_secondary_feed_links",
			"disable_self_pingbacks",
			"remove_rest_api_links",
			"disable_google_maps",
			"disable_password_strength_meter",
			"remove_comment_urls",
		]);
		const status = container.querySelector("[data-gtp-wordpress-preset-status]");

		container.querySelectorAll("[data-gtp-wordpress-preset]").forEach((button) => {
			button.addEventListener("click", () => {
				const useBaseline = button.dataset.gtpWordpressPreset === "gaurav";
				toggleKeys.forEach((key) => {
					const field = document.querySelector(`#gtp-bloat-${key}`);
					if (field) {
						field.checked = useBaseline && siteBaseline.has(key);
					}
				});
				status.textContent = useBaseline
					? "The gauravtiwari.org baseline is applied. Save changes to make it active."
					: "WordPress quick toggles are cleared. Save changes to make it active.";
			});
		});
	};

	const setupIntegrationDefaults = () => {
		const config = window.gtPerformanceAdmin;
		if (!config || typeof config.integrationProfiles !== "object") {
			return;
		}

		const fieldName = (section, key, list = false) =>
			`gt_performance_settings[${section}][${key}]${list ? "[]" : ""}`;

		const applyValue = (section, key, value) => {
			if (Array.isArray(value)) {
				const textField = document.querySelector(`#gtp-${section}-${key}`);
				if (textField && String(textField.value).trim() === "") {
					textField.value = value.join("\n");
					return true;
				}
				const fields = Array.from(
					document.querySelectorAll(`[name="${fieldName(section, key, true)}"]`),
				);
				if (!fields.length || fields.some((field) => field.checked)) {
					return false;
				}
				fields.forEach((field) => {
					field.checked = value.includes(field.value);
				});
				return true;
			}

			const field = document.querySelector(`#gtp-${section}-${key}`);
			if (!field) {
				return false;
			}
			if (field.type === "checkbox") {
				field.checked = Boolean(value);
				return true;
			}
			if (String(field.value).trim() !== "") {
				return false;
			}
			field.value = String(value);
			return true;
		};

		document.querySelectorAll("[data-gtp-enable-profile]").forEach((toggle) => {
			toggle.addEventListener("change", () => {
				if (!toggle.checked) {
					return;
				}

				const profile = config.integrationProfiles[toggle.dataset.gtpEnableProfile];
				if (!profile) {
					return;
				}

				let changes = 0;
				Object.entries(profile).forEach(([section, values]) => {
					Object.entries(values).forEach(([key, value]) => {
						changes += applyValue(section, key, value) ? 1 : 0;
					});
				});

				if (!changes) {
					return;
				}
				let status = toggle.closest(".gtp-field")?.querySelector("[data-gtp-integration-default-status]");
				if (!status) {
					status = document.createElement("p");
					status.dataset.gtpIntegrationDefaultStatus = "";
					status.setAttribute("aria-live", "polite");
					toggle.closest(".gtp-field")?.querySelector("div")?.append(status);
				}
				status.textContent = "Recommended defaults applied. Existing credentials and custom endpoints were preserved. Save changes to activate them.";
			});
		});
	};

	// <details> has no light dismiss of its own, so the notice popover would stay
	// open until it was clicked again. Close it on outside click and on Escape.
	const setupNoticePopover = () => {
		const disclosure = document.querySelector("[data-gtp-notice]");
		if (!disclosure) {
			return;
		}

		document.addEventListener("click", (event) => {
			if (disclosure.open && !disclosure.contains(event.target)) {
				disclosure.open = false;
			}
		});

		document.addEventListener("keydown", (event) => {
			if (event.key === "Escape" && disclosure.open) {
				disclosure.open = false;
				disclosure.querySelector("summary")?.focus();
			}
		});
	};

	const setupCssReport = () => {
		const button = document.querySelector('[data-gtp-css-refresh]');
		const report = document.querySelector('[data-gtp-css-report]');
		const message = document.querySelector('[data-gtp-css-message]');
		if (!button || !report || !message) return;
		button.addEventListener('click', async () => {
			button.disabled = true;
			report.setAttribute('aria-busy', 'true');
			message.textContent = '';
			try {
				const response = await fetch(gtPerformanceAdmin.ajaxUrl, {
					method: 'POST',
					body: new URLSearchParams({action: "gtperf_css_report", nonce: gtPerformanceAdmin.nonce}),
					signal: AbortSignal.timeout(30000)
				});
				const result = await response.json();
				if (!response.ok || !result.success || typeof result.data?.html !== 'string') throw new Error();
				report.innerHTML = result.data.html;
				message.textContent = gtPerformanceAdmin.cssRefreshed;
			} catch {
				message.textContent = gtPerformanceAdmin.cssRefreshFailed;
			} finally {
				button.disabled = false;
				report.removeAttribute('aria-busy');
			}
		});
	};
	setupCssReport();

	// A database cleanup runs in the background. While this screen is open, each
	// poll also advances it a little and shows the latest counts.
	const setupDatabaseRun = () => {
		const box = document.querySelector('[data-gtp-db-run]');
		if (!box || box.dataset.active !== '1') return;
		const poll = async () => {
			try {
				const response = await fetch(gtPerformanceAdmin.ajaxUrl, {
					method: 'POST',
					body: new URLSearchParams({action: 'gtperf_database_status', nonce: gtPerformanceAdmin.databaseNonce}),
					signal: AbortSignal.timeout(30000)
				});
				const result = await response.json();
				if (!response.ok || !result.success || typeof result.data?.html !== 'string') throw new Error();
				box.innerHTML = result.data.html;
				if (!result.data.active) {
					// Finished: reload for fresh counts, without the "started" notice.
					const url = new URL(window.location.href);
					url.searchParams.delete('gtperf_notice');
					window.location.replace(url.toString());
					return;
				}
			} catch {
				// The queue keeps working without this screen; try again shortly.
			}
			window.setTimeout(poll, 3000);
		};
		window.setTimeout(poll, 1000);
	};
	setupDatabaseRun();

	// Unused CSS reads server HTML, so it cannot see what page scripts build after
	// load. Render recent pages of each post type here, let their scripts run, and
	// report the classes and IDs that exist only in the live page.
	const setupScriptScan = () => {
		const panel = document.querySelector('[data-gtp-script-scan]');
		const config = gtPerformanceAdmin.scriptScan;
		if (!panel || !config) return;
		const start = panel.querySelector('[data-gtp-script-scan-start]');
		const stage = panel.querySelector('[data-gtp-script-scan-stage]');
		const status = panel.querySelector('[data-gtp-script-scan-status]');
		const results = panel.querySelector('[data-gtp-script-scan-results]');
		const failureList = panel.querySelector('[data-gtp-script-scan-failures]');
		const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));
		const PARALLEL = 3;
		const format = (text, ...values) => {
			let next = 0;
			return text.replace(/%(?:(\d+)\$)?[ds]/g, (match, position) => String(values[position ? Number(position) - 1 : next++]));
		};

		const tokensOf = (doc) => {
			const classes = new Set();
			const ids = new Set();
			doc.querySelectorAll('*').forEach((element) => {
				if (element.closest('#wpadminbar')) return;
				element.classList.forEach((name) => classes.add(name));
				if (element.id) ids.add(element.id);
			});
			return {classes, ids};
		};

		// The same page parsed without running any script.
		const serverTokens = async (url) => {
			const response = await fetch(url, {credentials: 'same-origin', signal: AbortSignal.timeout(30000)});
			if (!response.ok) throw new Error(`HTTP ${response.status}`);
			const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
			return {...tokensOf(doc), doc};
		};

		// A selector list split on its top-level commas only.
		const splitList = (text) => {
			const parts = [];
			let depth = 0;
			let quote = '';
			let start = 0;
			for (let i = 0; i < text.length; i++) {
				const c = text[i];
				if (c === '\\') {
					i++;
				} else if (quote) {
					if (c === quote) quote = '';
				} else if (c === '"' || c === "'") {
					quote = c;
				} else if (c === '(' || c === '[') {
					depth++;
				} else if (c === ')' || c === ']') {
					depth--;
				} else if (c === ',' && depth === 0) {
					parts.push(text.slice(start, i).trim());
					start = i + 1;
				}
			}
			parts.push(text.slice(start).trim());
			return parts.filter(Boolean);
		};

		// Tested against the element a rule decorates, as unused CSS tests it.
		const STATES = /:(?:focus-visible|focus-within|user-invalid|user-valid|placeholder-shown|indeterminate|popover-open|read-write|read-only|disabled|required|optional|invalid|visited|checked|enabled|active|target|autofill|closed|hover|focus|valid|open)(?![\w-])(?:\([^)]*\))?|::?(?:before|after|first-line|first-letter)(?![\w-])|::[\w-]+(?:\([^)]*\))?/gi;

		// Rules that match the live page but not its script-free HTML. They cover
		// selectors such as `.toc li` that name no class a script added.
		const scriptSelectors = (doc, server) => {
			const found = new Set();
			const visit = (rules) => {
				for (const rule of rules) {
					if (typeof rule.selectorText === 'string') {
						splitList(rule.selectorText).forEach((selector) => {
							const test = selector.replace(STATES, '').trim();
							if (!test || selector.includes('wpadminbar')) return;
							try {
								if (doc.querySelector(test) && !server.querySelector(test)) found.add(selector);
							} catch {
								// A selector this browser cannot evaluate protects nothing.
							}
						});
					} else if (rule.cssRules) {
						visit(rule.cssRules);
					}
				}
			};
			for (const sheet of doc.styleSheets) {
				try {
					visit(sheet.cssRules);
				} catch {
					// A cross-origin stylesheet served without CORS cannot be read.
				}
			}
			return found;
		};

		// The frame stays visible: scripts that wait for an element to scroll into
		// view never run in a frame that is off screen.
		const liveTokens = (url, width, server) => new Promise((resolve, reject) => {
			const height = width < 600 ? 844 : 800;
			const frame = document.createElement('iframe');
			frame.className = 'gtp-script-scan__frame';
			frame.title = config.frameTitle;
			frame.tabIndex = -1;
			frame.width = String(width);
			frame.height = String(height);
			let done = false;
			let timer = 0;
			const finish = async (timedOut) => {
				// A frame reports a load for its empty starting document too.
				if (done || (!timedOut && frame.contentDocument?.URL === 'about:blank')) return;
				done = true;
				window.clearTimeout(timer);
				try {
					const doc = frame.contentDocument;
					if (!doc || !doc.body || doc.URL === 'about:blank') throw new Error(config.blocked);
					await wait(1500);
					const view = frame.contentWindow;
					const step = Math.round(height * 0.8);
					for (let y = 0, steps = 0; y < doc.documentElement.scrollHeight && steps < 30; y += step, steps++) {
						view.scrollTo(0, y);
						await wait(100);
					}
					view.scrollTo(0, 0);
					await wait(600);
					resolve({...tokensOf(doc), selectors: scriptSelectors(doc, server)});
				} catch (error) {
					reject(error);
				} finally {
					slot.remove();
				}
			};
			// Frames run side by side, each scaled into its own slot.
			const scale = Math.min(stage.clientHeight / height, (stage.clientWidth / PARALLEL - 8) / width);
			const slot = document.createElement('div');
			slot.className = 'gtp-script-scan__slot';
			slot.style.width = `${Math.floor(width * scale)}px`;
			slot.style.height = `${Math.floor(height * scale)}px`;
			frame.style.transform = `scale(${scale})`;
			timer = window.setTimeout(() => finish(true), 30000);
			frame.addEventListener('load', () => finish(false));
			frame.src = url;
			slot.append(frame);
			stage.append(slot);
		});

		const next = panel.querySelector('[data-gtp-script-scan-next]');
		const widthName = (width) => (width < 600 ? config.mobile : config.desktop);

		// What to do once the check is over: see the rebuild, or turn the feature on.
		const showNext = (text, label, url) => {
			next.replaceChildren(document.createTextNode(`${text} `));
			const link = document.createElement('a');
			link.className = 'button button-primary';
			link.href = url;
			link.textContent = label;
			next.append(link);
			next.hidden = false;
		};

		const run = async () => {
			start.disabled = true;
			failureList.replaceChildren();
			failureList.hidden = true;
			next.hidden = true;
			const checks = [];
			Object.entries(config.targets).forEach(([type, target]) => {
				target.urls.forEach((url) => config.widths.forEach((width) => checks.push({type, label: target.label, url, width})));
			});
			if (!checks.length) {
				status.textContent = config.nothing;
				start.disabled = false;
				return;
			}

			const server = new Map();
			const found = {};
			const failures = [];
			const queue = [...checks];
			stage.hidden = false;
			let done = 0;
			const worker = async () => {
				while (queue.length) {
					const check = queue.shift();
					status.textContent = format(config.progress, done, checks.length, check.label, check.url, widthName(check.width));
					try {
						if (!server.has(check.url)) server.set(check.url, serverTokens(check.url));
						const before = await server.get(check.url);
						const after = await liveTokens(check.url, check.width, before.doc);
						const entry = (found[check.type] ??= {classes: new Set(), ids: new Map(), selectors: new Map(), pages: new Set()});
						const seen = (map, key) => map.set(key, (map.get(key) ?? new Set()).add(check.url));
						after.classes.forEach((name) => before.classes.has(name) || entry.classes.add(name));
						after.ids.forEach((id) => before.ids.has(id) || seen(entry.ids, id));
						after.selectors.forEach((selector) => seen(entry.selectors, selector));
						entry.pages.add(check.url);
					} catch (error) {
						failures.push(`${check.url} (${widthName(check.width)}): ${error.message}`);
					}
					done++;
				}
			};
			await Promise.all(Array.from({length: Math.min(PARALLEL, checks.length)}, worker));
			stage.replaceChildren();
			stage.hidden = true;
			status.textContent = config.saving;

			// Scripts often number headings or instances, and builders write per-page
			// rules. An ID, or a selector naming one, seen on a single page belongs to
			// that page and protects nothing anywhere else. With only one page checked
			// there is nothing to compare, so those are left out.
			const scan = Object.fromEntries(Object.entries(found).map(([type, entry]) => {
				const compared = entry.pages.size > 1;
				return [type, {
					classes: [...entry.classes],
					ids: compared ? [...entry.ids].filter(([, on]) => on.size > 1).map(([id]) => id) : [],
					selectors: [...entry.selectors].filter(([selector, on]) => (compared ? on.size > 1 : !selector.includes('#'))).map(([selector]) => selector),
					pages: entry.pages.size
				}];
			}));
			try {
				const response = await fetch(gtPerformanceAdmin.ajaxUrl, {
					method: 'POST',
					body: new URLSearchParams({action: 'gtperf_css_script_classes', nonce: config.nonce, scan: JSON.stringify(scan)}),
					signal: AbortSignal.timeout(120000)
				});
				const result = await response.json();
				if (!response.ok || !result.success || typeof result.data?.html !== 'string') throw new Error();
				results.innerHTML = result.data.html;
				if (failures.length === checks.length) {
					status.textContent = config.allFailed;
				} else if (!result.data.changed) {
					status.textContent = config.unchanged;
				} else {
					status.textContent = result.data.summary;
					if (config.cssEnabled) {
						showNext(config.rebuilding, config.seeStatus, config.statusUrl);
					} else {
						showNext(config.turnOn, config.enableCss, config.settingsUrl);
					}
				}
			} catch {
				status.textContent = config.saveFailed;
			}
			if (failures.length) {
				status.textContent += ` ${format(config.failures, failures.length)}`;
				failures.forEach((text) => {
					const item = document.createElement('li');
					item.textContent = text;
					failureList.append(item);
				});
				failureList.hidden = false;
			}
			start.disabled = false;
		};

		start.addEventListener('click', run);
		if (config.pending) run();
	};
	setupScriptScan();

	setupCachePresets();
	setupWordPressPresets();
	setupIntegrationDefaults();
	setupNoticePopover();
})();
