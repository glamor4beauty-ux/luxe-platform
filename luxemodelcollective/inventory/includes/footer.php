</main>
<script>
// Live EST clock in the header
(function() {
  function tick() {
    var now = new Date();
    var opts = {
      timeZone: 'America/New_York',
      weekday: 'short', month: 'short', day: 'numeric',
      hour: 'numeric', minute: '2-digit', second: '2-digit',
      hour12: true
    };
    var s = new Intl.DateTimeFormat('en-US', opts).format(now) + ' EST';
    var el = document.getElementById('estClock');
    if (el) el.textContent = s;
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
</body>
</html>
