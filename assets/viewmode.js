(function () {
    function getMeta() {
        return document.getElementById('viewportMeta') || document.querySelector('meta[name="viewport"]');
    }

    function apply(mode) {
    var vp = getMeta();
    var content;
    if (mode === 'desktop') {
        var w = window.innerWidth || 390;
        content = 'width=1280, initial-scale=' + (w / 1280).toFixed(4) + ', maximum-scale=5, user-scalable=yes';
        document.documentElement.setAttribute('data-view-mode', 'desktop');
    } else {
        content = 'width=device-width, initial-scale=1.0';
        document.documentElement.removeAttribute('data-view-mode');
    }

    if (vp) {
        var newVp = document.createElement('meta');
        newVp.name = 'viewport';
        newVp.id = 'viewportMeta';
        newVp.setAttribute('content', content);
        vp.parentNode.replaceChild(newVp, vp);
    }

    try { localStorage.setItem('mh-view-mode', mode); } catch (e) {}
    updateButtons(mode);
    setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 50);
}

    var ICON_DESKTOP = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="13" rx="1.5"/><path d="M8 21h8M12 17v4"/></svg>';
    var ICON_MOBILE = '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>';

    function updateButtons(mode) {
        document.querySelectorAll('.viewmode-toggle-btn').forEach(function (btn) {
            if (mode === 'desktop') {
                btn.innerHTML = ICON_MOBILE + '<span>Mobile</span>';
                btn.title = 'Tampilan saat ini: Desktop -- ketuk untuk kembali ke Mobile';
            } else {
                btn.innerHTML = ICON_DESKTOP + '<span>Desktop</span>';
                btn.title = 'Tampilan saat ini: Mobile -- ketuk untuk paksa tampilan Desktop';
            }
        });
    }

    window.toggleViewMode = function () {
        var current;
        try { current = localStorage.getItem('mh-view-mode'); } catch (e) {}
        apply(current === 'desktop' ? 'mobile' : 'desktop');
    };

    document.addEventListener('DOMContentLoaded', function () {
        var saved;
        try { saved = localStorage.getItem('mh-view-mode'); } catch (e) {}
        apply(saved === 'desktop' ? 'desktop' : 'mobile');
    });
})();
