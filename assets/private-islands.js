(() => {
	"use strict";

	const config = window.gtPerformancePrivateIslands;
	const islands = Array.from(document.querySelectorAll("[data-gtp-private-island][data-gtp-signature]"));
	if (!config || !islands.length) {
		return;
	}

	const requested = islands.map((island) => ({
		id: island.dataset.gtpPrivateIsland,
		signature: island.dataset.gtpSignature,
	}));
	const body = new URLSearchParams({
		action: "gtp_private_fragments",
		fragments: JSON.stringify(requested),
	});

	fetch(config.ajaxUrl, {
		method: "POST",
		credentials: "same-origin",
		headers: {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"},
		body: body.toString(),
	})
		.then((response) => response.json())
		.then((payload) => {
			if (!payload.success || !payload.data || !payload.data.fragments) {
				return;
			}
			islands.forEach((island) => {
				const html = payload.data.fragments[island.dataset.gtpPrivateIsland];
				if (typeof html === "string") {
					island.innerHTML = html;
					island.dataset.gtpPrivateReady = "true";
				}
			});
		})
		.catch(() => {
			// The cache-safe public fallback remains in place.
		});
})();
