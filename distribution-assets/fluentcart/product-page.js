(function () {
	'use strict';

	var presetValues = {
		maximum: {
			fresh: '1 hour',
			retention: '24 hours',
			error: '24 hours',
			browser: '5 minutes',
			message: 'Maximum impact preset applied. Origin cache stays fresh for 1 hour and retained for 24 hours.'
		},
		balanced: {
			fresh: '30 minutes',
			retention: '12 hours',
			error: '24 hours',
			browser: '5 minutes',
			message: 'Balanced preset applied. Browser max-age remains 5 minutes.'
		},
		dynamic: {
			fresh: '5 minutes',
			retention: '1 hour',
			error: '6 hours',
			browser: '5 minutes',
			message: 'Frequently updated preset applied. Browser max-age remains 5 minutes.'
		}
	};

	function initializeDemo(demo) {
		var tabs = Array.prototype.slice.call(demo.querySelectorAll('[data-demo-tab]'));
		var panels = Array.prototype.slice.call(demo.querySelectorAll('[data-demo-panel]'));
		var switches = Array.prototype.slice.call(demo.querySelectorAll('[data-demo-switch]'));
		var presets = Array.prototype.slice.call(demo.querySelectorAll('[data-demo-preset]'));
		var nativeControls = Array.prototype.slice.call(demo.querySelectorAll('input, textarea, select'));
		var liveRegion = demo.querySelector('[data-demo-live]');
		var cssTimer = null;

		switches.forEach(function (control) {
			control.dataset.initialChecked = control.getAttribute('aria-checked') || 'false';
		});

		nativeControls.forEach(function (control) {
			if ('checkbox' === control.type || 'radio' === control.type) {
				control.dataset.initialChecked = control.checked ? 'true' : 'false';
			} else {
				control.dataset.initialValue = control.value;
			}
		});

		function announce(message) {
			if (!liveRegion) {
				return;
			}

			liveRegion.textContent = '';
			window.setTimeout(function () {
				liveRegion.textContent = message;
			}, 20);
		}

		function selectTab(name, shouldFocus) {
			var selectedTab = null;

			tabs.forEach(function (tab) {
				var isSelected = tab.dataset.demoTab === name;
				tab.classList.toggle('is-active', isSelected);
				tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
				tab.setAttribute('tabindex', isSelected ? '0' : '-1');
				if (isSelected) {
					selectedTab = tab;
				}
			});

			panels.forEach(function (panel) {
				panel.hidden = panel.dataset.demoPanel !== name;
			});

			if (shouldFocus && selectedTab) {
				selectedTab.focus();
			}
		}

		function setStat(name, value) {
			var stat = demo.querySelector('[data-demo-stat="' + name + '"]');
			if (stat) {
				stat.textContent = value;
			}
		}

		function setSwitch(control, checked, shouldAnnounce) {
			var label = control.querySelector('b');
			var name = control.dataset.demoSwitch;
			var field = control.closest('.gtp-demo-field') || control.parentElement;
			var fieldLabel = field ? field.querySelector('strong') : null;
			var fieldName = fieldLabel ? fieldLabel.textContent.trim() : (name ? name.replace('-', ' ') : 'Setting');
			control.setAttribute('aria-checked', checked ? 'true' : 'false');
			control.setAttribute('aria-label', fieldName + ': ' + (checked ? 'On' : 'Off'));

			if (label) {
				label.textContent = checked ? 'On' : 'Off';
			}

			if ('cache' === name) {
				setStat('cache', checked ? 'Active' : 'Disabled');
			}

			if ('css' === name) {
				setStat('css', checked ? 'Enabled' : 'Disabled');
			}

			if (shouldAnnounce) {
				announce(fieldName + ' turned ' + (checked ? 'on.' : 'off.'));
			}
		}

		function applyPreset(name, shouldAnnounce) {
			var values = presetValues[name];
			if (!values) {
				return;
			}

			presets.forEach(function (preset) {
				preset.setAttribute('aria-pressed', preset.dataset.demoPreset === name ? 'true' : 'false');
			});

			Object.keys(values).forEach(function (key) {
				var output = demo.querySelector('[data-demo-value="' + key + '"]');
				if (output) {
					output.textContent = values[key];
				}
			});

			if (shouldAnnounce) {
				announce(values.message);
			}
		}

		function resetCssReport() {
			var status = demo.querySelector('[data-demo-css-status]');
			var output = demo.querySelector('[data-demo-css-output]');
			var processing = demo.querySelector('[data-demo-report="processing"]');
			var ready = demo.querySelector('[data-demo-report="ready"]');
			var readyStat = demo.querySelector('[data-demo-stat="css-ready"]');
			var button = demo.querySelector('[data-demo-generate-css]');

			if (status) {
				status.textContent = 'Processing';
				status.className = 'gtp-demo-state gtp-demo-state--processing';
			}
			if (output) {
				output.textContent = 'Analyzing…';
			}
			if (processing) {
				processing.textContent = '1';
			}
			if (ready) {
				ready.textContent = '18';
			}
			if (readyStat) {
				readyStat.textContent = '18';
			}
			if (button) {
				button.disabled = false;
				button.textContent = 'Simulate generation';
			}
		}

		function resetDemo(shouldAnnounce) {
			if (cssTimer) {
				window.clearTimeout(cssTimer);
				cssTimer = null;
			}

			switches.forEach(function (control) {
				setSwitch(control, 'true' === control.dataset.initialChecked, false);
			});
			nativeControls.forEach(function (control) {
				if ('checkbox' === control.type || 'radio' === control.type) {
					control.checked = 'true' === control.dataset.initialChecked;
				} else {
					control.value = control.dataset.initialValue || '';
				}
			});
			applyPreset('maximum', false);
			resetCssReport();
			selectTab('dashboard', false);

			var cloudflareBadge = demo.querySelector('[data-demo-cloudflare-badge]');
			if (cloudflareBadge) {
				cloudflareBadge.textContent = 'Connected';
				cloudflareBadge.className = 'gtp-demo-badge gtp-demo-badge--success';
			}
			setStat('cloudflare', 'Connected');

			var auth = demo.querySelector('[data-demo-auth]');
			if (auth) {
				auth.selectedIndex = 0;
			}

			if (shouldAnnounce) {
				announce('Interactive demo reset to maximum-impact defaults.');
			}
		}

		tabs.forEach(function (tab, index) {
			tab.addEventListener('click', function () {
				selectTab(tab.dataset.demoTab, false);
				announce(tab.textContent + ' tab opened.');
			});

			tab.addEventListener('keydown', function (event) {
				var targetIndex = index;

				if ('ArrowRight' === event.key) {
					targetIndex = (index + 1) % tabs.length;
				} else if ('ArrowLeft' === event.key) {
					targetIndex = (index - 1 + tabs.length) % tabs.length;
				} else if ('Home' === event.key) {
					targetIndex = 0;
				} else if ('End' === event.key) {
					targetIndex = tabs.length - 1;
				} else {
					return;
				}

				event.preventDefault();
				selectTab(tabs[targetIndex].dataset.demoTab, true);
			});
		});

		demo.querySelectorAll('[data-demo-open-tab]').forEach(function (button) {
			button.addEventListener('click', function () {
				selectTab(button.dataset.demoOpenTab, true);
				announce('CSS Reports tab opened.');
			});
		});

		switches.forEach(function (control) {
			control.addEventListener('click', function () {
				setSwitch(control, 'true' !== control.getAttribute('aria-checked'), true);
			});
		});

		presets.forEach(function (preset) {
			preset.addEventListener('click', function () {
				applyPreset(preset.dataset.demoPreset, true);
			});
		});

		var cloudflareButton = demo.querySelector('[data-demo-cloudflare]');
		if (cloudflareButton) {
			cloudflareButton.addEventListener('click', function () {
				var badge = demo.querySelector('[data-demo-cloudflare-badge]');
				cloudflareButton.disabled = true;
				cloudflareButton.textContent = 'Testing…';

				window.setTimeout(function () {
					cloudflareButton.disabled = false;
					cloudflareButton.textContent = 'Test connection';
					if (badge) {
						badge.textContent = 'Connected';
						badge.className = 'gtp-demo-badge gtp-demo-badge--success';
					}
					setStat('cloudflare', 'Connected');
					announce('Cloudflare Free connection is healthy and the managed Cache Rule is active.');
				}, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 500);
			});
		}

		var auth = demo.querySelector('[data-demo-auth]');
		if (auth) {
			auth.addEventListener('change', function () {
				announce(auth.value + ' selected. Credentials remain masked in this demo.');
			});
		}

		var wordpressPreset = demo.querySelector('[data-demo-wordpress-preset]');
		if (wordpressPreset) {
			wordpressPreset.addEventListener('click', function () {
				wordpressPreset.closest('.gtp-demo-card').querySelectorAll('[data-demo-switch]').forEach(function (control) {
					var label = control.closest('div').querySelector('strong');
					var shouldEnable = !label || !/RSS feeds|RSS feed links|Google Maps|password strength|comment author|favicon|global styles|block styles/i.test(label.textContent);
					setSwitch(control, shouldEnable, false);
				});
				announce('The gauravtiwari.org WordPress quick-toggle baseline has been applied in the demo.');
			});
		}

		nativeControls.forEach(function (control) {
			control.addEventListener('change', function () {
				if (control !== auth) {
					announce('Demo option updated. Nothing has been saved.');
				}
			});
		});

		var cssButton = demo.querySelector('[data-demo-generate-css]');
		if (cssButton) {
			cssButton.addEventListener('click', function () {
				var status = demo.querySelector('[data-demo-css-status]');
				var output = demo.querySelector('[data-demo-css-output]');
				var processing = demo.querySelector('[data-demo-report="processing"]');
				var ready = demo.querySelector('[data-demo-report="ready"]');
				var readyStat = demo.querySelector('[data-demo-stat="css-ready"]');

				if (cssTimer) {
					window.clearTimeout(cssTimer);
				}

				resetCssReport();
				cssButton.disabled = true;
				cssButton.textContent = 'Generating…';
				announce('Server-side unused CSS generation started.');

				cssTimer = window.setTimeout(function () {
					if (status) {
						status.textContent = 'Ready';
						status.className = 'gtp-demo-state gtp-demo-state--success';
					}
					if (output) {
						output.textContent = '28 KB · 81% saved';
					}
					if (processing) {
						processing.textContent = '0';
					}
					if (ready) {
						ready.textContent = '19';
					}
					if (readyStat) {
						readyStat.textContent = '19';
					}
					cssButton.disabled = false;
					cssButton.textContent = 'Generate again';
					cssTimer = null;
					announce('Unused CSS is ready. Critical rules are inline and the remaining 28 kilobytes load as a file.');
				}, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 900);
			});
		}

		demo.querySelectorAll('[data-demo-action]').forEach(function (button) {
			button.addEventListener('click', function () {
				announce(button.dataset.demoAction + '.');
			});
		});

		var resetButton = demo.querySelector('[data-demo-reset]');
		if (resetButton) {
			resetButton.addEventListener('click', function () {
				resetDemo(true);
			});
		}

		resetDemo(false);
	}

	document.querySelectorAll('[data-gtp-product-demo]').forEach(initializeDemo);
})();
