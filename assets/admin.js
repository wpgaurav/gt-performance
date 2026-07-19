(() => {
	"use strict";

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
})();
