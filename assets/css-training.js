(() => {
	"use strict";

	if (typeof window.gtPerformanceCssTraining !== "object") {
		return;
	}

	const pending = new Set();
	const ignored = new Set(["active", "focus", "hover"]);

	const selectorFor = (element) => {
		if (!(element instanceof Element) || element.closest("#wpadminbar")) {
			return "";
		}
		if (element.id && !element.id.startsWith("gtp-")) {
			return `#${CSS.escape(element.id)}`;
		}

		const classes = Array.from(element.classList)
			.filter((name) => name.length <= 64 && !ignored.has(name) && !name.startsWith("gtp-"))
			.slice(0, 4);
		if (!classes.length) {
			return "";
		}

		return `${element.tagName.toLowerCase()}${classes.map((name) => `.${CSS.escape(name)}`).join("")}`;
	};

	const capture = (element) => {
		const selector = selectorFor(element);
		if (selector && selector.length <= 120) {
			pending.add(selector);
		}
	};

	["click", "focusin", "pointerover", "change"].forEach((eventName) => {
		document.addEventListener(eventName, (event) => capture(event.target), true);
	});

	const observer = new MutationObserver((mutations) => {
		mutations.forEach((mutation) => {
			capture(mutation.target);
			mutation.addedNodes.forEach((node) => capture(node));
		});
	});
	observer.observe(document.documentElement, {
		subtree: true,
		childList: true,
		attributes: true,
		attributeFilter: ["class", "id", "open", "hidden", "aria-expanded", "aria-selected", "aria-checked"],
	});

	const send = async () => {
		if (!pending.size) {
			return;
		}
		const selectors = Array.from(pending).slice(0, 100);
		selectors.forEach((selector) => pending.delete(selector));
		const body = new URLSearchParams({
			action: "gtp_css_training_observe",
			nonce: window.gtPerformanceCssTraining.nonce,
		});
		selectors.forEach((selector) => body.append("selectors[]", selector));

		try {
			await fetch(window.gtPerformanceCssTraining.ajaxUrl, {
				method: "POST",
				credentials: "same-origin",
				headers: {"Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"},
				body: body.toString(),
			});
		} catch (error) {
			selectors.forEach((selector) => pending.add(selector));
		}
	};

	window.setInterval(send, 2000);
})();
