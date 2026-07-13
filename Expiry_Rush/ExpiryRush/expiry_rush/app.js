(function () {
  'use strict';
  function updateTimers() {
    document.querySelectorAll('[data-expires]').forEach(function (el) {
      var expires = parseInt(el.dataset.expires, 10);
      var diff = expires - Math.floor(Date.now() / 1000);
      if (diff <= 0) {
        el.textContent = 'Expired';
        el.className = 'timer timer-red';
        return;
      }
      var text;
      if (diff < 60) {
        text = diff + 's left';
      } else if (diff < 3600) {
        text = Math.floor(diff / 60) + 'm ' + (diff % 60) + 's left';
      } else if (diff < 86400) {
        text = Math.floor(diff / 3600) + 'h ' + Math.floor((diff % 3600) / 60) + 'm left';
      } else {
        text = Math.floor(diff / 86400) + 'd left';
      }
      el.textContent = text;
      if (diff < 3600) {
        el.className = 'timer timer-red';
      } else if (diff < 21600) {
        el.className = 'timer timer-orange';
      } else {
        el.className = 'timer timer-green';
      }
    });
  }
  var lockExpiredReload = false;
  function updateLockTimers() {
    var anyExpired = false;
    document.querySelectorAll('[data-lock-expires]').forEach(function (el) {
      var expires = parseInt(el.dataset.lockExpires, 10);
      var diff = expires - Math.floor(Date.now() / 1000);
      if (diff <= 0) {
        el.textContent = '⚠ Lock expired — remove and re-add';
        el.style.color = 'var(--red)';
        anyExpired = true;
        return;
      }
      var mins = Math.floor(diff / 60);
      var secs = diff % 60;
      el.textContent = '⏱ ' + mins + 'm ' + secs + 's lock remaining';
      el.style.color = diff < 120 ? 'var(--orange)' : '';
    });
    if (anyExpired && !lockExpiredReload) {
      lockExpiredReload = true;
      setTimeout(function () { location.reload(); }, 1500);
    }
  }
  document.addEventListener('click', function (e) {
    if (e.target.id && e.target.id.startsWith('alert-modal-')) {
      e.target.style.display = 'none';
    }
    if (e.target.id === 'pay-modal') {
      e.target.style.display = 'none';
    }
  });
  updateTimers();
  updateLockTimers();
  setInterval(updateTimers, 1000);
  setInterval(updateLockTimers, 1000);
})();