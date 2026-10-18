# Sincronizador del API — complemento de Galaxytool NG

Descarga del [API público de OGame](https://forum.origin.ogame.gameforge.com/forum/thread/44-ogame-api/)
las partes `serverData`, `alliances`, `players`, `universe`, `highscore` (12 tipos de ranking de jugadores y alianzas, con historial diario en `puntuaciones_historial`) y `localization` (nombres de unidades y misiones) de cada universo y las vuelca en las tablas del
núcleo (`universos`, `alliances`, `players`, `universe`). Recuerda el `timestamp` del último documento
importado para no reimportar lo que no ha cambiado.

## Instalación

Requiere el núcleo de [Galaxytool NG](https://github.com/GalaxytoolNG/gui) 1.1.0 o superior (tablas `puntuaciones`, `puntuaciones_historial` y `localizacion`).

```bash
cd plugins && git clone https://github.com/GalaxytoolNG/sincronizador_api.git
cd .. && bin/galaxytool complementos:instalar sincronizador_api && bin/galaxytool complementos:activar sincronizador_api
```

O desde `/admin/complementos`: «Instalar» y «Activar». Al instalar, copia los datos de la antigua tabla
`sincronizaciones` del núcleo si existe y convierte el antiguo permiso global `es_operador` en nivel
`operador` de este complemento; los usuarios con algún universo asociado reciben nivel `acceso`.

## Uso

- Web: `/sincronizar`. Cada usuario ve sus universos asociados; con nivel `operador`, todos los conocidos.
- API: `GET|POST /api/sincronizar/<dominio>/<parte>` con clave de API (`Authorization: Bearer …`) de un
  usuario con nivel `operador`. Ejemplo: `/api/sincronizar/s275-es.ogame.gameforge.com/players`.
- Línea de órdenes (pensada para cron):

```bash
bin/galaxytool sincronizador_api:sincronizar es275 players
bin/galaxytool sincronizador_api:sincronizar es275 highscore      # cada hora o pocas horas
bin/galaxytool sincronizador_api:sincronizar es275 localization   # basta una vez por semana
bin/galaxytool sincronizador_api:sincronizar s275-es.ogame.gameforge.com todas
```

## Niveles

| Nivel | Permite |
|---|---|
| acceso | sincronizar los universos asociados al usuario |
| operador | sincronizar cualquier universo conocido, también por API |
| administrador | cambiar el tiempo de espera en `/admin/complementos/sincronizador_api` |

## Licencia

GPL-3.0-or-later.
