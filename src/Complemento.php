<?php

declare(strict_types=1);

namespace Complementos\SincronizadorApi;

use App\Base\Contenedor;
use App\Complementos\ComplementoBase;
use App\Servicios\UsuarioServicio;

final class Complemento extends ComplementoBase
{
    public const NOMBRE = 'sincronizador_api';

    public function rutas(): array
    {
        $partes = implode('|', SincronizacionServicio::APIS);
        return [
            'GET #^$#' => [SincronizadorControlador::class, 'pagina', 'acceso'],
            "POST #^/([^/]+)/($partes)$#" => [SincronizadorControlador::class, 'ejecutar', 'acceso'],
            "#^/api/([^/]+)/($partes)$#" => [SincronizadorControlador::class, 'api', 'operador'],
        ];
    }

    public function comandos(): array
    {
        return [
            'sincronizar' => [$this, 'comandoSincronizar'],
        ];
    }

    /**
     * Tras instalar: recupera los datos de la antigua tabla `sincronizaciones` del núcleo (si existe)
     * y convierte el permiso global `es_operador` en nivel operador; los usuarios con universos
     * asociados reciben nivel acceso para no perder la pantalla.
     */
    public function instalado(Contenedor $contenedor): void
    {
        $pdo = $contenedor->pdo();
        try {
            $pdo->exec(
                'INSERT INTO c_sincronizador_api_partes (id_universo, parte, timestamp, momento, registros)
                 SELECT s.id_universo, s.parte, s.timestamp, s.momento, s.registros FROM sincronizaciones s
                 WHERE NOT EXISTS (SELECT 1 FROM c_sincronizador_api_partes p WHERE p.id_universo = s.id_universo AND p.parte = s.parte)'
            );
        } catch (\PDOException $e) {
            // La tabla antigua ya no existe: instalación nueva.
        }

        $registro = $contenedor->registro();
        $operadores = $pdo->query("SELECT id_usuario FROM permisos WHERE permiso = 'es_operador'")->fetchAll(\PDO::FETCH_COLUMN);
        $asociados = $pdo->query('SELECT DISTINCT id_usuario FROM usuarios_universos')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (array_unique(array_merge($operadores, $asociados)) as $idUsuario) {
            $idUsuario = (int)$idUsuario;
            $niveles = $registro->nivelesDeUsuario($idUsuario);
            $esOperador = in_array($idUsuario, array_map('intval', $operadores), true);
            if ($esOperador) {
                $registro->establecerNivel($idUsuario, self::NOMBRE, 'operador');
                if (!isset($niveles[$contenedor->nucleo()->nombre()])) {
                    $registro->establecerNivel($idUsuario, $contenedor->nucleo()->nombre(), 'operador');
                }
            } elseif (!isset($niveles[self::NOMBRE])) {
                $registro->establecerNivel($idUsuario, self::NOMBRE, 'acceso');
            }
        }
    }

    /** bin/galaxytool sincronizador_api:sincronizar <es275|s275-es.ogame.gameforge.com> [parte|todas] */
    public function comandoSincronizar(array $args): int
    {
        $universo = $args[0] ?? '';
        $parte = $args[1] ?? 'todas';
        if ($universo === '') {
            fwrite(STDERR, "Uso: bin/galaxytool sincronizador_api:sincronizar <universo> [serverData|alliances|players|universe|todas]\n");
            return 1;
        }
        $url = str_contains($universo, '.') ? $universo : UsuarioServicio::urlDeUniverso($universo);
        $partes = $parte === 'todas' ? SincronizacionServicio::APIS : [$parte];
        $servicio = new SincronizacionServicio($this->contenedor->pdo(), (int)$this->configuracion()['tiempo_espera']);
        $codigo = 0;
        foreach ($partes as $p) {
            try {
                $r = $servicio->sincronizar($url, $p);
                printf("%-10s %-12s %s (%d registros, datos de %s)\n", $r['id_universo'], $p, $r['sin_cambios'] ? 'sin cambios' : 'ok', $r['registros'], date('d/m/Y H:i', $r['timestamp']));
            } catch (\RuntimeException $e) {
                printf("%-10s %-12s ERROR %s\n", $universo, $p, $e->getMessage());
                $codigo = 1;
            }
        }
        return $codigo;
    }
}
