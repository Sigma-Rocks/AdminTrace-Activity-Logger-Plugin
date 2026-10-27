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
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done);
				return;
			}
			var area = document.createElement('textarea');
			area.value = text;
			area.setAttribute('readonly', 'readonly');
			area.style.position = 'absolute';
			area.style.left = '-9999px';
			document.body.appendChild(area);
			area.select();
			try {
				document.execCommand('copy');
				done();
			} catch (error) {
				return;
			}
			document.body.removeChild(area);
		});
	});
}());
