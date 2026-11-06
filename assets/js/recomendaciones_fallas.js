'use strict';
document.querySelectorAll('[data-rf-delete]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
        if (!window.confirm('¿Borrar esta publicación? Dejará de ser visible para todos los usuarios.')) event.preventDefault();
    });
});
