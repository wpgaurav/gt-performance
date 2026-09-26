/** GT Performance click-to-play YouTube previews. */
document.addEventListener('click', function (event) {
	const button = event.target.closest('.gtp-youtube button');
	if (!button) return;
	const wrapper = button.parentNode;
	const query = wrapper.dataset.videoQuery || '';
	const iframe = document.createElement('iframe');
	iframe.src = 'https://www.youtube-nocookie.com/embed/' + wrapper.dataset.videoId + '?' + (query ? query + '&' : '') + 'autoplay=1';
	iframe.allow = 'autoplay; encrypted-media; picture-in-picture';
	iframe.allowFullscreen = true;
	iframe.title = button.getAttribute('aria-label') || '';
	iframe.style = 'width:100%;height:100%;border:0';
	wrapper.replaceChildren(iframe);
	iframe.focus();
});
