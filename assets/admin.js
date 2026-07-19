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
			"remove_feed_links",
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
			"remove_feed_links",
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

	const setupCssReport = () => {
		const report = document.querySelector("[data-gtp-css-report]");

		if (!report || typeof window.gtPerformanceAdmin !== "object") {
			return;
		}

		const rows = report.querySelector("[data-gtp-css-report-rows]");
		const note = report.querySelector("[data-gtp-report-note]");
		let stopped = false;

		const refresh = async () => {
			if (stopped || document.hidden) {
				return;
			}

			const body = new URLSearchParams({
				action: "gtp_css_report",
				nonce: window.gtPerformanceAdmin.nonce,
			});

			try {
				const response = await fetch(window.gtPerformanceAdmin.ajaxUrl, {
					method: "POST",
					credentials: "same-origin",
					headers: {
						"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
					},
					body: body.toString(),
				});
				const payload = await response.json();

				if (!response.ok || !payload.success) {
					throw new Error("Report refresh failed.");
				}

				rows.innerHTML = payload.data.rows;
				Object.entries(payload.data.summary).forEach(([status, count]) => {
					const target = document.querySelector(`[data-gtp-count="${status}"]`);
					if (target) {
						target.textContent = String(count);
					}
				});
				note.textContent = `Updated ${new Date().toLocaleTimeString()}`;
			} catch (error) {
				note.textContent = "Live refresh paused. Reload the page to try again.";
				stopped = true;
			}
		};

		window.setInterval(refresh, 3000);
	};

	setupCachePresets();
	setupWordPressPresets();
	setupCssReport();
})();
