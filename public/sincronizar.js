/* Sincronizador del API de OGame: ejecuta las llamadas una a una y muestra el estado en cada celda. */
(function () {
    'use strict';

    var raiz = document.getElementById('sincronizador');
    if (!raiz) {
        return;
    }

    var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
    var apis = (raiz.dataset.apis || '').split(',');
    var filas = Array.prototype.slice.call(raiz.querySelectorAll('tbody tr[data-universo]'));
    var casillas = Array.prototype.slice.call(raiz.querySelectorAll('input.seleccion'));
    var resumen = document.getElementById('resumen');
    var trabajando = false;

    function celda(fila, api) {
        return fila.querySelector('td.celda-api[data-api="' + api + '"]');
    }

    function ponerEstado(td, estado, texto) {
        var marca = td.querySelector('.estado');
        var detalle = td.querySelector('.detalle');
        marca.dataset.estado = estado;
        marca.title = texto || '';
        detalle.textContent = texto || '';
        detalle.className = 'detalle ' + estado;
    }

    function marcarTodo(valor) {
        casillas.forEach(function (c) { c.checked = valor; });
        actualizarResumen();
    }

    function marcarFila(idUniverso, valor) {
        filas.forEach(function (fila) {
            if (fila.dataset.universo === idUniverso) {
                fila.querySelectorAll('input.seleccion').forEach(function (c) { c.checked = valor; });
            }
        });
        actualizarResumen();
    }

    function marcarColumna(api, valor) {
        filas.forEach(function (fila) {
            celda(fila, api).querySelector('input.seleccion').checked = valor;
        });
        actualizarResumen();
    }

    function actualizarResumen() {
        var n = casillas.filter(function (c) { return c.checked; }).length;
        resumen.textContent = n ? n + ' llamada' + (n === 1 ? '' : 's') + ' seleccionada' + (n === 1 ? '' : 's') : '';
        filas.forEach(function (fila) {
            var propias = Array.prototype.slice.call(fila.querySelectorAll('input.seleccion'));
            var caja = fila.querySelector('input[data-marcar-fila]');
            caja.checked = propias.every(function (c) { return c.checked; });
            caja.indeterminate = !caja.checked && propias.some(function (c) { return c.checked; });
        });
        apis.forEach(function (api) {
            var columna = filas.map(function (fila) { return celda(fila, api).querySelector('input.seleccion'); });
            var caja = raiz.querySelector('input[data-columna="' + api + '"]');
            caja.checked = columna.length > 0 && columna.every(function (c) { return c.checked; });
            caja.indeterminate = !caja.checked && columna.some(function (c) { return c.checked; });
        });
        var todas = raiz.querySelector('input[data-marcar-fila="todas"]');
        todas.checked = casillas.length > 0 && n === casillas.length;
        todas.indeterminate = n > 0 && n < casillas.length;
    }

    function tareasSeleccionadas() {
        var tareas = [];
        filas.forEach(function (fila) {
            apis.forEach(function (api) {
                var td = celda(fila, api);
                if (td.querySelector('input.seleccion').checked) {
                    tareas.push({ fila: fila, api: api, td: td });
                }
            });
        });
        return tareas;
    }

    function todasLasTareas() {
        var tareas = [];
        filas.forEach(function (fila) {
            apis.forEach(function (api) {
                tareas.push({ fila: fila, api: api, td: celda(fila, api) });
            });
        });
        return tareas;
    }

    function sincronizarUna(tarea) {
        ponerEstado(tarea.td, 'trabajando', 'Descargando…');
        var url = '/sincronizar/' + encodeURIComponent(tarea.fila.dataset.url) + '/' + tarea.api;
        return fetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF': csrf },
            credentials: 'same-origin'
        }).then(function (respuesta) {
            return respuesta.json().catch(function () {
                throw new Error('Respuesta no válida del servidor (' + respuesta.status + ')');
            });
        }).then(function (datos) {
            if (datos.error) {
                throw new Error(datos.mensaje || 'Error');
            }
            var texto = datos.sin_cambios ? 'Sin cambios · datos de ' + datos.fecha : 'OK · ' + datos.registros + ' registros · datos de ' + datos.fecha;
            if (datos.aviso) {
                texto += ' · Aviso: ' + datos.aviso;
            }
            ponerEstado(tarea.td, datos.aviso ? 'error' : 'ok', texto);
            var ultima = tarea.td.querySelector('.ultima');
            if (ultima) {
                ultima.textContent = datos.fecha;
            }
        }).catch(function (error) {
            ponerEstado(tarea.td, 'error', 'Error: ' + error.message);
        });
    }

    function ejecutar(tareas) {
        if (trabajando || !tareas.length) {
            return;
        }
        trabajando = true;
        raiz.classList.add('trabajando');
        raiz.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
        tareas.forEach(function (t) { ponerEstado(t.td, 'cola', 'En cola'); });

        var hechas = 0;
        var errores = 0;
        function siguiente(i) {
            if (i >= tareas.length) {
                trabajando = false;
                raiz.classList.remove('trabajando');
                raiz.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
                resumen.textContent = 'Terminado: ' + (hechas - errores) + ' correctas, ' + errores + ' con error.';
                return;
            }
            resumen.textContent = 'Sincronizando ' + (i + 1) + ' de ' + tareas.length + '…';
            sincronizarUna(tareas[i]).then(function () {
                hechas++;
                if (tareas[i].td.querySelector('.estado').dataset.estado === 'error') {
                    errores++;
                }
                siguiente(i + 1);
            });
        }
        siguiente(0);
    }

    raiz.querySelector('[data-marcar="todo"]').addEventListener('click', function () { marcarTodo(true); });
    raiz.querySelector('[data-desmarcar="todo"]').addEventListener('click', function () { marcarTodo(false); });
    raiz.querySelector('input[data-marcar-fila="todas"]').addEventListener('change', function () { marcarTodo(this.checked); });
    raiz.querySelectorAll('tbody input[data-marcar-fila]').forEach(function (caja) {
        caja.addEventListener('change', function () { marcarFila(caja.dataset.marcarFila, caja.checked); });
    });
    raiz.querySelectorAll('input[data-columna]').forEach(function (caja) {
        caja.addEventListener('change', function () { marcarColumna(caja.dataset.columna, caja.checked); });
    });
    casillas.forEach(function (c) { c.addEventListener('change', actualizarResumen); });
    raiz.querySelectorAll('[data-sincronizar-fila]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            var id = boton.dataset.sincronizarFila;
            ejecutar(todasLasTareas().filter(function (t) { return t.fila.dataset.universo === id; }));
        });
    });
    document.getElementById('sincronizar-seleccion').addEventListener('click', function () {
        var tareas = tareasSeleccionadas();
        if (!tareas.length) {
            resumen.textContent = 'No hay nada seleccionado.';
            return;
        }
        ejecutar(tareas);
    });
    document.getElementById('sincronizar-todo').addEventListener('click', function () {
        ejecutar(todasLasTareas());
    });

    actualizarResumen();
})();
