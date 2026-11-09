(function () {
    var COOKIE_NAME = 'device_fp';
    var MAX_AGE = 31536000;

    function canvasSignature() {
        try {
            var canvas = document.createElement('canvas');
            canvas.width = 240;
            canvas.height = 60;
            var ctx = canvas.getContext('2d');
            if (!ctx) {
                return '';
            }
            ctx.textBaseline = 'top';
            ctx.font = '16px Arial';
            ctx.fillStyle = '#f60';
            ctx.fillRect(100, 1, 62, 20);
            ctx.fillStyle = '#069';
            ctx.fillText('广播室指纹 ABC', 2, 15);
            ctx.fillStyle = 'rgba(102, 204, 0, 0.7)';
            ctx.fillText('广播室指纹 ABC', 4, 17);
            return canvas.toDataURL();
        } catch (err) {
            return '';
        }
    }

    function hash(input) {
        var h1 = 0x811c9dc5;
        var h2 = 0x01000193;
        for (var i = 0; i < input.length; i++) {
            var code = input.charCodeAt(i);
            h1 ^= code;
            h1 = Math.imul(h1, 0x01000193) >>> 0;
            h2 = Math.imul(h2 ^ code, 0x85ebca6b) >>> 0;
        }
        return ('00000000' + h1.toString(16)).slice(-8) + ('00000000' + h2.toString(16)).slice(-8);
    }

    function collect() {
        var nav = window.navigator || {};
        var scr = window.screen || {};
        var timezone = '';
        try {
            timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (err) {
            timezone = '';
        }

        var parts = [
            nav.userAgent || '',
            nav.platform || '',
            nav.language || '',
            (nav.languages || []).join(','),
            String(nav.hardwareConcurrency || ''),
            String(nav.deviceMemory || ''),
            String(scr.width) + 'x' + String(scr.height) + 'x' + String(scr.colorDepth || ''),
            String(window.devicePixelRatio || ''),
            String(new Date().getTimezoneOffset()),
            timezone,
            canvasSignature()
        ];

        return hash(parts.join('|'));
    }

    try {
        document.cookie = COOKIE_NAME + '=' + collect() + '; path=/; max-age=' + MAX_AGE + '; SameSite=Lax';
    } catch (err) {
        // 忽略指纹采集失败，页面功能不受影响。
    }
})();
