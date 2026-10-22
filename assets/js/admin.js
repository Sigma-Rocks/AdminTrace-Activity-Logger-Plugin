(function () {
	var config = window.advanceHistoryAdmin || {};

	document.querySelectorAll('[data-ah-confirm]').forEach(function (element) {
		element.addEventListener('click', function (event) {
			var message = element.getAttribute('data-ah-confirm');
			if (message && !window.confirm(message)) {
				event.preventDefault();
			}
		});
	});

	var selectAll = document.querySelector('[data-ah-select-all]');
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			document.querySelectorAll('.ah-event-check').forEach(function (box) {
				box.checked = selectAll.checked;
			});
		});
	}

	document.querySelectorAll('[data-ah-copy]').forEach(function (button) {
		button.addEventListener('click', function () {
			var node = document.getElementById(button.getAttribute('data-ah-copy'));
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
