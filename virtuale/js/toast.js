// js/toast.js — Sistem i thjeshtë toast për portalin Virtuale

(function () {
  function iconFor(type) {
    switch (type) {
      case 'success': return '<i class="fa-solid fa-circle-check"></i>';
      case 'error':   return '<i class="fa-solid fa-triangle-exclamation"></i>';
      case 'warning': return '<i class="fa-solid fa-circle-exclamation"></i>';
      default:        return '<i class="fa-solid fa-circle-info"></i>';
    }
  }

  function titleFor(type) {
    switch (type) {
      case 'success': return 'Sukses';
      case 'error':   return 'Gabim';
      case 'warning': return 'Kujdes';
      default:        return 'Njoftim';
    }
  }

  function createToast(type, message, timeoutMs) {
    const root = document.getElementById('toast-root');
    if (!root) return;

    const t = document.createElement('div');
    t.className = 'toast-item toast-item--' + (type || 'info');
    t.innerHTML = `
      <div class="toast-accent"></div>
      <div class="toast-body">
        <div class="toast-icon">${iconFor(type)}</div>
        <div class="toast-content">
          <div class="toast-title">${titleFor(type)}</div>
          <div class="toast-text">${message}</div>
        </div>
        <button class="toast-close" aria-label="Mbyll njoftimin">&times;</button>
      </div>
    `;

    const closeBtn = t.querySelector('.toast-close');
    closeBtn.addEventListener('click', function () {
      t.classList.add('toast-item--closing');
      t.style.opacity = '0';
      setTimeout(() => t.remove(), 150);
    });

    root.appendChild(t);

    if (timeoutMs !== 0) {
      setTimeout(() => {
        if (t.parentNode) {
          t.style.opacity = '0';
          setTimeout(() => t.remove(), 150);
        }
      }, timeoutMs || 4000);
    }
  }

  window.AppToast = {
    show: function (type, message, timeoutMs) {
      if (!message) return;
      createToast(type || 'info', message, timeoutMs);
    }
  };
})();
