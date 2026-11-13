(function () {
  var script = document.currentScript;
  var endpoint = script && script.getAttribute('data-beacon-path') || '/api/pa/hit';

  if (
    location.pathname.startsWith('/admin') ||
    navigator.webdriver === true ||
    navigator.doNotTrack === '1' ||
    window.doNotTrack === '1' ||
    navigator.globalPrivacyControl
  ) {
    return;
  }

  var sent = false;

  function recordVisiblePage() {
    if (sent || document.prerendering || document.visibilityState !== 'visible') {
      return;
    }

    sent = true;
    var data = JSON.stringify({
      p: location.pathname,
      r: document.referrer || null,
      s: new URLSearchParams(location.search).get('utm_source')
    });

    if (navigator.sendBeacon) {
      navigator.sendBeacon(endpoint, new Blob([data], { type: 'application/json' }));
    } else {
      fetch(endpoint, {
        method: 'POST',
        body: data,
        headers: { 'Content-Type': 'application/json' },
        keepalive: true
      });
    }
  }

  document.addEventListener('visibilitychange', recordVisiblePage);
  document.addEventListener('prerenderingchange', recordVisiblePage);
  recordVisiblePage();
})();
