<?php

declare(strict_types=1);

namespace Complementos\SincronizadorApi;

use App\Repositorios\AlianzaRepositorio;
use App\Repositorios\JugadorRepositorio;
use App\Repositorios\UniversoDatosRepositorio;
use App\Repositorios\UniversoRepositorio;
use App\Servicios\OGameApiServicio;
use App\Servicios\UsuarioServicio;

/**
 * Descarga una de las partes del API público de OGame (serverData, alliances, players,
 * universe) y la vuelca en la base de datos.
 */
final class SincronizacionServicio
{
    public const APIS = ['serverData', 'alliances', 'players', 'universe'];

    public const ETIQUETAS = [
        'serverData' => 'Configuración',
        'alliances' => 'Alianzas',
        'players' => 'Jugadores',
        'universe' => 'Universo',
    ];

    private \PDO $pdo;
    private int $tiempoEspera;

    public function __construct(\PDO $pdo, int $tiempoEspera = 60)
    {
        $this->pdo = $pdo;
        $this->tiempoEspera = $tiempoEspera;
    }

    /**
     * @return array{id_universo: string, api: string, timestamp: int, registros: int}
     */
    public function sincronizar(string $url, string $api): array
    {
        if (!in_array($api, self::APIS, true)) {
            throw new \RuntimeException("API no soportada: $api");
        }
        if (!preg_match('/^s\d+-[a-z]+\.ogame\.gameforge\.com$/', $url)) {
            throw new \RuntimeException("Dominio de universo no válido: $url");
        }

        $idUniverso = UsuarioServicio::idDeUrl($url);
        $servicioOgame = new OGameApiServicio($this->tiempoEspera);
        $doc = $servicioOgame->descargar("https://$url/api/$api.xml");

        $serverId = $doc->atributo('serverId');
        if ($serverId !== null && $serverId !== $idUniverso) {
            throw new \RuntimeException(sprintf(
                'El XML de %s dice pertenecer al universo %s, no a %s',
                $url,
                $serverId,
                $idUniverso
            ));
        }
        $timestamp = $doc->timestamp();
        if ($timestamp <= 0) {
            throw new \RuntimeException("El XML de $api no indica timestamp");
        }

        // Si el API devuelve el mismo documento que la última vez, no hay nada que importar.
        if ($this->timestampSincronizado($idUniverso, $api) === $timestamp) {
            return [
                'id_universo' => $idUniverso,
                'api' => $api,
                'timestamp' => $timestamp,
                'registros' => 0,
                'sin_cambios' => true,
            ];
        }

        $repositorio = match ($api) {
            'serverData' => new UniversoDatosRepositorio($this->pdo),
            'alliances' => new AlianzaRepositorio($this->pdo),
            'players' => new JugadorRepositorio($this->pdo),
            'universe' => new UniversoRepositorio($this->pdo),
        };
        $registros = (int)$repositorio->actualizarDesdeOgame($idUniverso, $doc);
        $this->registrarSincronizacion($idUniverso, $api, $timestamp, $registros);

        try {
            \App\Base\Contenedor::instancia()->eventos()->emitir('api.sincronizada', [
                'id_universo' => $idUniverso,
                'parte' => $api,
                'timestamp' => $timestamp,
                'registros' => $registros,
            ]);
        } catch (\LogicException $e) {
            // Sin contenedor inicializado no hay suscriptores.
        }

        return [
            'id_universo' => $idUniverso,
            'api' => $api,
            'timestamp' => $timestamp,
            'registros' => $registros,
            'sin_cambios' => false,
        ];
    }

    /** Timestamp del último documento importado de esa parte y universo, o null. */
    private function timestampSincronizado(string $idUniverso, string $api): ?int
    {
        $stmt = $this->pdo->prepare('SELECT timestamp FROM c_sincronizador_api_partes WHERE id_universo = ? AND parte = ?');
        $stmt->execute([$idUniverso, $api]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    private function registrarSincronizacion(string $idUniverso, string $api, int $timestamp, int $registros): void
    {
        $motor = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = match ($motor) {
            'sqlite', 'pgsql' => 'INSERT INTO c_sincronizador_api_partes (id_universo, parte, timestamp, momento, registros) VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (id_universo, parte) DO UPDATE SET timestamp = excluded.timestamp, momento = excluded.momento, registros = excluded.registros',
            'mysql' => 'INSERT INTO c_sincronizador_api_partes (id_universo, parte, timestamp, momento, registros) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE timestamp = VALUES(timestamp), momento = VALUES(momento), registros = VALUES(registros)',
            default => throw new \RuntimeException("Motor no soportado: $motor"),
        };
        $this->pdo->prepare($sql)->execute([$idUniverso, $api, $timestamp, time(), $registros]);
    }

    /**
     * Última fecha de datos por universo y API, a partir del campo
     * `actualizado` de cada tabla.
     *
     * @return array<string, array<string, int>>  [id_universo => [api => timestamp]]
     */
    public function ultimasActualizaciones(): array
    {
        $resultado = [];
        // Fecha del último documento importado (tabla c_sincronizador_api_partes)…
        foreach ($this->pdo->query('SELECT id_universo, parte, timestamp FROM c_sincronizador_api_partes')->fetchAll(\PDO::FETCH_ASSOC) as $fila) {
            $resultado[$fila['id_universo']][$fila['parte']] = (int)$fila['timestamp'];
        }
        // …y, para universos importados antes de existir esa tabla, la fecha más reciente de los datos.
        $tablas = ['serverData' => 'universos', 'alliances' => 'alliances', 'players' => 'players', 'universe' => 'universe'];
        foreach ($tablas as $api => $tabla) {
            $filas = $this->pdo->query(
                "SELECT id_universo, MAX(actualizado) AS ultimo FROM $tabla GROUP BY id_universo"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($filas as $fila) {
                $resultado[$fila['id_universo']][$api] ??= (int)$fila['ultimo'];
            }
        }
        return $resultado;
    }
}
