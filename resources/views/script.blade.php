<script @if ($nonce) nonce="{{ $nonce }}" @endif>
(function () {
    var endpoint = @json($endpoint);
    function track(name, properties) {
        if (!name) { return; }
        var body = JSON.stringify({ name: String(name), properties: properties || {}, path: location.pathname });
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }))) { return; }
        } catch (e) {}
        try {
            fetch(endpoint, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' } });
        } catch (e) {}
    }
    var api = { track: track };
    window.fpa = api;
    if (!window.analytics) { window.analytics = api; }
    document.addEventListener('click', function (event) {
        var el = event.target && event.target.closest ? event.target.closest('[data-analytics-event]') : null;
        if (!el) { return; }
        var properties = {};
        for (var i = 0; i < el.attributes.length; i++) {
            var attr = el.attributes[i];
            if (attr.name.indexOf('data-analytics-') === 0 && attr.name !== 'data-analytics-event') {
                properties[attr.name.slice(15)] = attr.value;
            }
        }
        track(el.getAttribute('data-analytics-event'), properties);
    }, true);
})();
</script>
