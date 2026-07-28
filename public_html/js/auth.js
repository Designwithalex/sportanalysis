// Comportamiento de las pantallas de sesión (login, registro, perfil).
//
// Los formularios de auth se postean al servidor de forma clásica, no por fetch: la validación
// real, los mensajes y el redirect los decide PHP. Este archivo solo cubre lo que el servidor
// no puede: mostrar/ocultar la contraseña, el medidor de fuerza y el estado de carga del submit.
// Sin JS los formularios siguen funcionando enteros.
(function () {
    'use strict';

    // ---- Ver / ocultar contraseña -------------------------------------------------
    document.querySelectorAll('.field-password-toggle').forEach(function (btn) {
        var wrap = btn.closest('.field-password');
        var input = wrap ? wrap.querySelector('input') : null;
        if (!input) return;

        btn.addEventListener('click', function () {
            var revealed = input.type === 'text';
            input.type = revealed ? 'password' : 'text';
            wrap.classList.toggle('is-revealed', !revealed);
            btn.setAttribute('aria-pressed', String(!revealed));
            btn.setAttribute('aria-label', revealed ? 'Mostrar contraseña' : 'Ocultar contraseña');
        });
    });

    // ---- Medidor de fuerza ---------------------------------------------------------
    // Orientativo, no bloqueante: el mínimo real lo exige el servidor. Cuenta largo y variedad
    // de familias de caracteres, no diccionarios: prometer más que eso sería mentira.
    var LEVELS = ['Muy débil', 'Débil', 'Aceptable', 'Fuerte'];

    function strength(value) {
        if (!value) return 0;
        var families = 0;
        if (/[a-záéíóúñ]/.test(value)) families++;
        if (/[A-ZÁÉÍÓÚÑ]/.test(value)) families++;
        if (/[0-9]/.test(value)) families++;
        if (/[^\w\s]/.test(value)) families++;

        if (value.length < 8) return 1;
        if (value.length >= 14 && families >= 3) return 3;
        if (value.length >= 10 && families >= 2) return 3;
        if (families >= 2) return 2;
        return 1;
    }

    document.querySelectorAll('[data-pw-meter]').forEach(function (input) {
        var meter = document.getElementById(input.getAttribute('data-pw-meter'));
        if (!meter) return;
        var text = meter.querySelector('.pw-meter-text');

        function update() {
            var level = strength(input.value);
            meter.setAttribute('data-level', String(level));
            if (text) text.textContent = input.value ? LEVELS[level] : '';
        }
        input.addEventListener('input', update);
        update();
    });

    // ---- Estado de carga del submit ------------------------------------------------
    // Bandera en vez de `disabled`: deshabilitar el botón que tiene el foco lo tira al <body>
    // y el lector de pantalla pierde el hilo. Además, un botón deshabilitado no envía su valor.
    document.querySelectorAll('form[data-loading-form]').forEach(function (form) {
        var sent = false;
        form.addEventListener('submit', function (e) {
            if (sent) { e.preventDefault(); return; }
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
            sent = true;
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.classList.add('is-loading');
                btn.setAttribute('aria-busy', 'true');
            }
        });
    });

    // ---- Limpiar el error de un campo apenas se corrige -----------------------------
    document.querySelectorAll('.field.has-error input, .field.has-error select').forEach(function (input) {
        input.addEventListener('input', function () {
            var field = input.closest('.field');
            if (!field || !field.classList.contains('has-error')) return;
            field.classList.remove('has-error');
            input.removeAttribute('aria-invalid');
            var err = field.querySelector('.field-error');
            if (err) err.textContent = '';
        }, { once: true });
    });
})();
