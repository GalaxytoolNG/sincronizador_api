<?php

declare(strict_types=1);

namespace Complementos\SincronizadorApi;

use App\Repositorios\AlianzaRepositorio;
use App\Repositorios\JugadorRepositorio;
use App\Repositorios\UniversoDatosRepositorio;
use App\Repositorios\LocalizacionRepositorio;
use App\Repositorios\PuntuacionRepositorio;
use App\Repositorios\UniversoRepositorio;
use App\Servicios\OGameApiServicio;
use App\Servicios\UsuarioServicio;

/**
 * Descarga una de las partes del API público de OGame (serverData, alliances, players,
 * universe) y la vuelca en la base de datos.
 */
final class SincronizacionServicio
{
    public const APIS = ['serverData', 'alliances', 'players', 'universe', 'highscore', 'localization'];

    /** Tipos de ranking que se descargan por categoría (1 jugadores, 2 alianzas). */
    public const TIPOS_HIGHSCORE = [1 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11], 2 => [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]];
    private const PAUSA_ENTRE_TIPOS_MS = 1000;
    private const PAUSA_REINTENTO_MS = 5000;

    public const ETIQUETAS = [
        'serverData' => 'Configuración',
        'alliances' => 'Alianzas',
        'players' => 'Jugadores',
        'universe' => 'Universo',
        'highscore' => 'Puntuaciones',
        'localization' => 'Localización',
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
        if ($api === 'highscore') {
            return $this->sincronizarHighscore($url, $idUniverso, $servicioOgame);
        }
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
            'localization' => new LocalizacionRepositorio($this->pdo),
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

    /**
     * highscore.xml es un documento por categoría y tipo (hasta 24): se descargan todos con una pausa,
     * se toleran los tipos que el API rechace (400/503) y se registra como una sola parte con el
     * timestamp del primer documento.
     */
    private function sincronizarHighscore(string $url, string $idUniverso, OGameApiServicio $servicioOgame): array
    {
        $repositorio = new PuntuacionRepositorio($this->pdo);
        $registros = 0;
        $timestamp = 0;
        $fallidos = [];
        $pendientes = [];
        foreach (self::TIPOS_HIGHSCORE as $categoria => $tipos) {
            foreach ($tipos as $tipo) {
                $pendientes[] = [$categoria, $tipo];
            }
        }
        // El API limita el ritmo (503 si se encadenan peticiones): pausa entre tipos y un segundo intento
        // de los que fallen tras una pausa mayor.
        for ($intento = 1; $intento <= 2 && $pendientes; $intento++) {
            if ($intento === 2) {
                usleep(self::PAUSA_REINTENTO_MS * 1000);
            }
            $fallidos = [];
            foreach ($pendientes as $n => [$categoria, $tipo]) {
                if ($n > 0 || $intento === 2) {
                    usleep(self::PAUSA_ENTRE_TIPOS_MS * 1000);
                }
                try {
                    $doc = $servicioOgame->descargar("https://$url/api/highscore.xml?category=$categoria&type=$tipo");
                } catch (\RuntimeException $e) {
                    $fallidos[] = [$categoria, $tipo];
                    continue;
                }
                // El ranking total de jugadores (1/0) es la referencia: si no ha cambiado, no hay nada que importar.
                if ($categoria === 1 && $tipo === 0) {
                    $timestamp = $doc->timestamp();
                    if ($timestamp > 0 && $this->timestampSincronizado($idUniverso, 'highscore') === $timestamp) {
                        return ['id_universo' => $idUniverso, 'api' => 'highscore', 'timestamp' => $timestamp, 'registros' => 0, 'sin_cambios' => true];
                    }
                }
                $timestamp = max($timestamp, $doc->timestamp());
                $registros += $repositorio->actualizarDesdeOgame($idUniverso, $doc, $categoria, $tipo);
            }
            $pendientes = $fallidos;
        }
        $fallidos = array_map(fn($f) => $f[0] . '/' . $f[1], $fallidos);
        if ($timestamp <= 0) {
            throw new \RuntimeException('No se pudo descargar ningún tipo de highscore' . ($fallidos ? ' (' . implode(', ', $fallidos) . ')' : ''));
        }
        $this->registrarSincronizacion($idUniverso, 'highscore', $timestamp, $registros);
        try {
            \App\Base\Contenedor::instancia()->eventos()->emitir('api.sincronizada', [
                'id_universo' => $idUniverso, 'parte' => 'highscore', 'timestamp' => $timestamp, 'registros' => $registros,
            ]);
        } catch (\LogicException $e) {
        }
        if ($fallidos) {
            error_log("Sincronizador: highscore de $idUniverso sin los tipos " . implode(', ', $fallidos));
        }
        return [
            'id_universo' => $idUniverso, 'api' => 'highscore', 'timestamp' => $timestamp, 'registros' => $registros, 'sin_cambios' => false,
            'aviso' => $fallidos ? 'el API no devolvió los tipos ' . implode(', ', $fallidos) . '; vuelva a sincronizar más tarde' : null,
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
        $tablas = ['serverData' => 'universos', 'alliances' => 'alliances', 'players' => 'players', 'universe' => 'universe', 'highscore' => 'puntuaciones', 'localization' => 'localizacion'];
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
