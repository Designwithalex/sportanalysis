// Raíz pública deducida de la URL de este mismo script (…/js/api.js?v=123 → …/).
// Hardcodear '/login.php' funcionaría en producción (la raíz pública ES el dominio) pero se
// rompería en cualquier instalación colgada de un subdirectorio. Se calcula al cargar, que es
// el único momento en que document.currentScript apunta a este archivo.
const ApiRoot = (function () {
    const s = document.currentScript;
    if (s && s.src) {
        const m = s.src.match(/^(.*\/)js\/api\.js(?:\?.*)?$/);
        if (m) return m[1];
    }
    return '/';
})();

const Api = {
    async get(url) {
        const res = await fetch(url, { headers: Api._headers() });
        return Api._handle(res);
    },
    async postForm(url, formData) {
        const res = await fetch(url, { method: 'POST', body: formData, headers: Api._headers() });
        return Api._handle(res);
    },
    async del(url) {
        const res = await fetch(url, { method: 'DELETE', headers: Api._headers() });
        return Api._handle(res);
    },

    // Token anti-CSRF de <meta name="csrf">. No se pone Content-Type: con FormData el navegador
    // tiene que armar el boundary del multipart él mismo.
    _headers() {
        const meta = document.querySelector('meta[name="csrf"]');
        const token = meta ? meta.content : '';
        return token ? { 'X-CSRF-Token': token } : {};
    },

    // Sesión caída/expirada: no sirve de nada mostrar "ocurrió un error" en una pantalla que
    // ya no puede hacer nada. Se va a login conservando a dónde quería ir el usuario.
    _redirectToLogin() {
        let next = '';
        try {
            const base = new URL(ApiRoot, window.location.href).pathname;
            const here = window.location.pathname;
            if (here.startsWith(base)) next = here.slice(base.length) + window.location.search;
        } catch (e) { /* sin next, login.php usa su default */ }

        window.location.replace(ApiRoot + 'login.php' + (next ? '?next=' + encodeURIComponent(next) : ''));
    },

    async _handle(res) {
        if (res.status === 401) {
            Api._redirectToLogin();
            // El redirect no corta la ejecución en el acto: el throw evita que quien llamó siga
            // trabajando con datos que no existen mientras el navegador navega.
            throw new Error('Tu sesión expiró. Redirigiendo a la pantalla de ingreso…');
        }
        const data = await res.json();
        if (!res.ok || !data.ok) {
            throw new Error(data.error || 'Ocurrió un error inesperado.');
        }
        return data;
    },
};
