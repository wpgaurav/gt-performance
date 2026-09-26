/** GT Performance interaction-delayed scripts. */
(() => {
	let started = false;
	const load = () => {
		if (started) return;
		started = true;
		document.querySelectorAll('script[type="text/gtp-delayed"][data-gtp-src]').forEach((original, index) => {
			const script = document.createElement('script');
			for (const attribute of original.attributes) {
				if (!['type', 'data-gtp-src', 'async', 'defer'].includes(attribute.name)) {
					script.setAttribute(attribute.name, attribute.value);
				}
			}
			if (original.nonce) script.nonce = original.nonce;
			script.src = original.dataset.gtpSrc;
			script.async = false;
			script.defer = true;
			script.dataset.gtpOrder = String(index);
			original.replaceWith(script);
		});
	};
	['pointerdown', 'keydown', 'touchstart'].forEach(event => addEventListener(event, load, { once: true, passive: true }));
	setTimeout(load, 5000);
})();
