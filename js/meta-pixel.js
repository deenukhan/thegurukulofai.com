/* ==========================================================================
   META PIXEL — masterclass pages only
   The browser half of Meta tracking. The server sends its own Purchase through
   the Conversions API (api/razorpay/lib.php), with the same event ID, so Meta
   counts each sale once even when an ad blocker stops this file.

   The Pixel ID is public by design. Keep it equal to 'pixel_id' in the private
   server config. The access token never goes here.
   ========================================================================== */

(function () {
  var PIXEL_ID = '3622381407931275';

  // Only the live site reports to Meta. Local previews queue events without sending them.
  var live = /(^|\.)thegurukulofai\.com$/.test(window.location.hostname);

  if (!window.fbq) {
    var n = window.fbq = function () {
      if (n.callMethod) n.callMethod.apply(n, arguments);
      else n.queue.push(arguments);
    };
    if (!window._fbq) window._fbq = n;
    n.push = n;
    n.loaded = true;
    n.version = '2.0';
    n.queue = [];
    if (live) {
      var s = document.createElement('script');
      s.async = true;
      s.src = 'https://connect.facebook.net/en_US/fbevents.js';
      document.head.appendChild(s);
    }
  }

  window.fbq('init', PIXEL_ID);
  window.fbq('track', 'PageView');

  var fired = {};
  window.mcMeta = {
    // eventId lets Meta match this event to the server's copy of it.
    track: function (name, data, eventId) {
      try {
        if (eventId) window.fbq('track', name, data || {}, { eventID: eventId });
        else window.fbq('track', name, data || {});
      } catch (e) { /* tracking must never break the page */ }
    },
    // Fires once per page view, however many times the trigger happens.
    once: function (name, data) {
      if (fired[name]) return;
      fired[name] = true;
      this.track(name, data);
    },
  };
})();
