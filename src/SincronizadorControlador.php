<?php

declare(strict_types=1);

namespace Complementos\SincronizadorApi;

use App\Base\ControladorWeb;
use App\Servicios\UsuarioServicio;
use App\Utilidades\Respuesta;

final class SincronizadorControlador extends ControladorWeb
{
    /** GET /sincronizar */
    public function pagina(): void
    {
        $this->requerirNivel(Complemento::NOMBRE, 'acceso');
        $servicio = $this->servicio();

        $this->renderizar(__DIR__ . '/../vistas/sincronizar.php', [
            'universos' => $this->universosPermitidos((int)$this->usuario['id']),
            'apis' => SincronizacionServicio::ETIQUETAS,
            'ultimas' => $servicio->ultimasActualizaciones(),
            'puede_todo' => $this->tieneNivel(Complemento::NOMBRE, 'operador'),
        ], [
            'titulo' => 'Sincronizador',
            'menu_activo' => Complemento::NOMBRE,
        ]);
    }

    /** POST /sincronizar/{url}/{parte}: una sincronización desde la web, responde JSON. */
    public function ejecutar(string $url, string $api): void
    {
        $this->requerirNivel(Complemento::NOMBRE, 'acceso');
        $this->comprobarCsrf();

        $idUniverso = UsuarioServicio::idDeUrl($url);
        if (!$this->tieneNivel(Complemento::NOMBRE, 'operador') && !$this->usuarios->estaAsociado((int)$this->usuario['id'], $idUniverso)) {
            $this->json(Respuesta::error("No tiene acceso al universo $idUniverso"), 403);
            return;
        }
        $this->responderSincronizacion($url, $api, true);
    }

    /**
     * GET|POST /api/sincronizar/{url}/{parte}: con clave de API. El núcleo ya ha autenticado la clave
     * y comprobado el nivel operador antes de llegar aquí.
     */
    public function api(string $url, string $api): void
    {
        $this->responderSincronizacion($url, $api, false);
    }

    private function responderSincronizacion(string $url, string $api, bool $conFecha): void
    {
        try {
            $resultado = $this->servicio()->sincronizar($url, $api);
            $respuesta = [
                'error' => false,
                'mensaje' => $resultado['sin_cambios'] ? 'sin cambios' : 'ok',
                'sin_cambios' => $resultado['sin_cambios'],
                'id_universo' => $resultado['id_universo'],
                'api' => $resultado['api'],
                'registros' => $resultado['registros'],
                'timestamp' => $resultado['timestamp'],
            ];
            if ($conFecha) {
                $respuesta['fecha'] = date('d/m/Y H:i', $resultado['timestamp']);
            }
            $this->json($respuesta);
        } catch (\PDOException $e) {
            error_log('Sincronizador: ' . $e->getMessage());
            $this->json(Respuesta::error('Error interno del servidor'), 500);
        } catch (\RuntimeException $e) {
            $this->json(Respuesta::error($e->getMessage()), 400);
        }
    }

    private function servicio(): SincronizacionServicio
    {
        $contenedor = $this->contenedor();
        $cfg = $contenedor->registro()->configuracion($contenedor->cargador()->manifiestoDe(Complemento::NOMBRE));
        return new SincronizacionServicio($this->pdo, max(5, (int)($cfg['tiempo_espera'] ?? 60)));
    }

    /** Universos asociados al usuario y, con nivel operador, todos los conocidos. */
    private function universosPermitidos(int $idUsuario): array
    {
        $asociados = [];
        foreach ($this->usuarios->universosDeUsuario($idUsuario) as $fila) {
            $asociados[$fila['id_universo']] = $fila;
        }
        $ids = array_keys($asociados);
        if ($this->tieneNivel(Complemento::NOMBRE, 'operador')) {
            $ids = array_unique(array_merge($ids, $this->usuarios->universosConocidos()));
        }
        natsort($ids);

        $universos = [];
        foreach ($ids as $id) {
            $universos[] = [
                'id_universo' => $id,
                'url' => UsuarioServicio::urlDeUniverso($id),
                'nombre_jugador' => $asociados[$id]['nombre_jugador'] ?? null,
                'id_player' => $asociados[$id]['id_player'] ?? null,
                'propio' => isset($asociados[$id]),
            ];
        }
        return $universos;
    }
}
