(function () {
	var config = window.srahAdmin || {};

	document.querySelectorAll('[data-srah-confirm]').forEach(function (element) {
		element.addEventListener('click', function (event) {
			var message = element.getAttribute('data-srah-confirm');
			if (message && !window.confirm(message)) {
				event.preventDefault();
			}
		});
	});

	var selectAll = document.querySelector('[data-srah-select-all]');
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			document.querySelectorAll('.srah-event-check').forEach(function (box) {
				box.checked = selectAll.checked;
			});
		});
	}

	document.querySelectorAll('[data-srah-copy]').forEach(function (button) {
		button.addEventListener('click', function () {
			var node = document.getElementById(button.getAttribute('data-srah-copy'));
			if (!node) {
				return;
			}
			var text = node.innerText || node.textContent || '';
			var done = function () {
				var previous = button.textContent;
				button.textContent = config.copied || 'Copied';
				window.setTimeout(function () {
					button.textContent = previous;
				}, 1600);
			};
			var fallback = function () {
				var area = document.createElement('textarea');
				area.value = text;
				area.setAttribute('readonly', 'readonly');
				area.style.position = 'absolute';
				area.style.left = '-9999px';
				document.body.appendChild(area);
				area.select();
				try {
					if (document.execCommand('copy')) {
						done();
					}
				} catch (error) {
					// Copying is unavailable; the text stays selectable on the page.
				}
				document.body.removeChild(area);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, fallback);
				return;
			}
			fallback();
		});
	});

	var modal = document.getElementById('srah-email-modal');
	if (!modal || !window.ajaxurl) {
		return;
	}

	var title = document.getElementById('srah-email-modal-title');
	var meta = document.getElementById('srah-email-modal-meta');
	var frame = modal.querySelector('.srah-email-frame');
	var plain = modal.querySelector('.srah-email-plain');
	var source = modal.querySelector('.srah-email-source');
	var tabs = document.getElementById('srah-email-tabs');
	var status = document.getElementById('srah-email-status');
	var headers = document.getElementById('srah-email-modal-headers');
	var headerText = headers ? headers.querySelector('pre') : null;
	var actions = document.getElementById('srah-email-actions');
	var resendForm = document.getElementById('srah-email-resend');
	var resendId = resendForm ? resendForm.querySelector('input[name="email_id"]') : null;
	var resendNote = document.getElementById('srah-email-resend-note');
	var deleteForm = document.getElementById('srah-email-delete');
	var deleteId = deleteForm ? deleteForm.querySelector('input[name="email_ids[]"]') : null;
	var lastFocus = null;
	var currentHtml = '';

	function line(label, value, className) {
		if (!value) {
			return null;
		}
		var row = document.createElement('p');
		if (className) {
			row.className = className;
		}
		var name = document.createElement('strong');
		name.textContent = label;
		row.appendChild(name);
		row.appendChild(document.createTextNode(' ' + value));
		return row;
	}

	function closeModal() {
		modal.hidden = true;
		document.body.classList.remove('srah-modal-open');
		if (frame) {
			frame.hidden = true;
			frame.style.height = '';
			frame.removeAttribute('srcdoc');
		}
		if (plain) {
			plain.hidden = true;
			plain.textContent = '';
		}
		if (source) {
			source.hidden = true;
			source.textContent = '';
		}
		if (tabs) {
			tabs.hidden = true;
		}
		currentHtml = '';
		if (status) {
			status.hidden = true;
			status.textContent = '';
		}
		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	function openModal(opener) {
		lastFocus = opener || document.activeElement;
		modal.hidden = false;
		document.body.classList.add('srah-modal-open');
		var close = modal.querySelector('.srah-modal-close');
		if (close) {
			close.focus();
		}
	}

	function showStatus(text) {
		if (frame) {
			frame.hidden = true;
			frame.style.height = '';
			frame.removeAttribute('srcdoc');
		}
		if (plain) {
			plain.hidden = true;
			plain.textContent = '';
		}
		if (source) {
			source.hidden = true;
		}
		if (tabs) {
			tabs.hidden = true;
		}
		if (status) {
			status.hidden = false;
			status.textContent = text;
		}
	}

	function setTab(which) {
		if (tabs) {
			tabs.querySelectorAll('[data-srah-email-tab]').forEach(function (tab) {
				var on = tab.getAttribute('data-srah-email-tab') === which;
				tab.classList.toggle('is-active', on);
				tab.setAttribute('aria-selected', on ? 'true' : 'false');
			});
		}
		if (which === 'html') {
			if (frame) {
				frame.hidden = true;
			}
			if (plain) {
				plain.hidden = true;
			}
			if (source) {
				source.hidden = false;
				source.textContent = currentHtml;
			}
			return;
		}
		if (source) {
			source.hidden = true;
		}
		if (frame) {
			frame.hidden = false;
		}
	}

	function showPlain(text) {
		if (status) {
			status.hidden = true;
		}
		if (frame) {
			frame.hidden = true;
			frame.style.height = '';
			frame.removeAttribute('srcdoc');
		}
		if (source) {
			source.hidden = true;
		}
		if (plain) {
			plain.hidden = false;
			plain.textContent = text;
		}
	}

	function fitFrame() {
		if (!frame || frame.hidden || !frame.contentDocument || !frame.contentDocument.body) {
			return;
		}
		var doc = frame.contentDocument;
		var body = doc.body;
		var root = doc.documentElement;
		frame.style.height = '0px';
		var content = Math.max(body ? body.scrollHeight : 0, root ? root.scrollHeight : 0);
		var room = frame.parentElement ? frame.parentElement.clientHeight : 0;
		frame.style.height = Math.max(content + 24, room) + 'px';
		Array.prototype.forEach.call(doc.images || [], function (image) {
			if (!image.complete) {
				image.addEventListener('load', fitFrame, { once: true });
			}
		});
	}

	if (frame) {
		frame.addEventListener('load', fitFrame);
	}

	function previewDocument(html) {
		if (/<html[\s>]/i.test(html)) {
			return html;
		}
		return '<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank"></head><body>' + html + '</body></html>';
	}

	function loadPreview(id, opener) {
		openModal(opener);
		if (title) {
			title.textContent = config.emailLoading || 'Loading the email…';
		}
		if (meta) {
			meta.textContent = '';
		}
		if (headers) {
			headers.hidden = true;
		}
		if (actions) {
			actions.hidden = true;
		}
		if (resendNote) {
			resendNote.hidden = true;
		}
		currentHtml = '';
		if (tabs) {
			tabs.hidden = true;
		}
		showStatus(config.emailLoading || 'Loading the email…');

		var body = new window.FormData();
		body.append('action', 'srah_email_preview');
		body.append('_ajax_nonce', config.emailPreview || '');
		body.append('id', id);

		window.fetch(window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success || !payload.data) {
				var message = payload && payload.data && payload.data.message ? payload.data.message : (config.emailMissing || 'That email could not be loaded.');
				if (title) {
					title.textContent = config.emailMissing || 'That email could not be loaded.';
				}
				showStatus(message);
				return;
			}
			var email = payload.data;
			if (title) {
				title.textContent = email.subject || '';
			}
			if (meta) {
				var labels = config.emailLabels || {};
				meta.textContent = '';
				[line(labels.to, email.to), line(labels.when, email.when), line(labels.status, email.status), line(labels.error, email.error, 'srah-modal-error'), line(labels.files, email.files), line(labels.sender, email.sender)].forEach(function (node) {
					if (node) {
						meta.appendChild(node);
					}
				});
			}
			currentHtml = email.message || '';
			if (email.html && frame) {
				if (status) {
					status.hidden = true;
				}
				if (plain) {
					plain.hidden = true;
					plain.textContent = '';
				}
				if (tabs) {
					tabs.hidden = false;
				}
				frame.style.height = '100%';
				frame.srcdoc = previewDocument(currentHtml);
				setTab('rich');
				window.requestAnimationFrame(fitFrame);
				window.setTimeout(fitFrame, 200);
			} else {
				if (tabs) {
					tabs.hidden = true;
				}
				showPlain(currentHtml);
			}
			if (headers && headerText && email.headers) {
				headerText.textContent = email.headers;
				headers.hidden = false;
			}
			if (resendId) {
				resendId.value = String(email.id || id);
			}
			if (deleteId) {
				deleteId.value = String(email.id || id);
			}
			if (resendNote) {
				resendNote.hidden = !email.files;
			}
			if (actions) {
				actions.hidden = false;
			}
		}).catch(function () {
			if (title) {
				title.textContent = config.emailMissing || 'That email could not be loaded.';
			}
			showStatus(config.emailMissing || 'That email could not be loaded.');
		});
	}

	document.querySelectorAll('[data-srah-email]').forEach(function (button) {
		button.addEventListener('click', function () {
			loadPreview(button.getAttribute('data-srah-email'), button);
		});
	});

	if (tabs) {
		tabs.querySelectorAll('[data-srah-email-tab]').forEach(function (tab) {
			tab.addEventListener('click', function () {
				setTab(tab.getAttribute('data-srah-email-tab'));
			});
		});
	}

	modal.querySelectorAll('[data-srah-email-close]').forEach(function (control) {
		control.addEventListener('click', closeModal);
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && !modal.hidden) {
			closeModal();
		}
	});

	var initial = modal.getAttribute('data-srah-email-open');
	if (initial) {
		loadPreview(initial, null);
	}
}());
