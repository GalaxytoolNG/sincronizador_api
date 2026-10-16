<?php

use App\Base\Vista;

/** @var array $universos */
/** @var array $apis */
/** @var array $ultimas */
/** @var bool $puede_todo */
$e = fn($t) => Vista::e($t);
$fecha = fn(?int $t) => $t ? date('d/m/Y H:i', $t) : '—';
?>
<link rel="stylesheet" href="/complementos/sincronizador_api/sincronizar.css">
<?php if (!$universos) : ?>
    <div class="caja">
        <p>No tiene ningún universo asociado. Pida a un administrador que asocie su usuario a su jugador en cada universo.</p>
    </div>
<?php else : ?>
<p class="explicacion">
    Marque las partes del API de OGame que desee descargar y pulse <strong>Sincronizar seleccionados</strong>,
    o pulse <strong>Sincronizar todo</strong> para actualizar todos los universos. Las llamadas se hacen una a una;
    cada celda muestra la rueda mientras trabaja y un <span class="ok">OK</span> cuando OGame responde.
</p>

<div class="listado" id="sincronizador" data-apis="<?= $e(implode(',', array_keys($apis))) ?>">
    <div class="titulo">
        <h3>Universos</h3>
        <div class="acciones-globales">
            <button type="button" class="btn" data-marcar="todo">Marcar todos</button>
            <button type="button" class="btn" data-desmarcar="todo">Desmarcar todos</button>
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th><input type="checkbox" data-marcar-fila="todas" title="Marcar o desmarcar todo"></th>
                <th>Universo</th>
                <th>Jugador</th>
                <?php foreach ($apis as $api => $etiqueta) : ?>
                    <th class="columna-api">
                        <label><input type="checkbox" data-columna="<?= $e($api) ?>" title="Marcar toda la columna"> <?= $e($etiqueta) ?></label>
                    </th>
                <?php endforeach; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($universos as $u) : ?>
            <tr data-universo="<?= $e($u['id_universo']) ?>" data-url="<?= $e($u['url']) ?>">
                <td><input type="checkbox" data-marcar-fila="<?= $e($u['id_universo']) ?>" title="Marcar toda la fila"></td>
                <td class="principal">
                    <?= $e($u['id_universo']) ?>
                    <?php if ($u['propio']) : ?><span class="etiqueta-nuevo" title="Universo asociado a su usuario">PROPIO</span><?php endif; ?>
                    <span class="secundario"><?= $e($u['url']) ?></span>
                </td>
                <td><?= $u['nombre_jugador'] !== null ? $e($u['nombre_jugador']) : ($u['id_player'] ? 'id ' . (int)$u['id_player'] : '—') ?></td>
                <?php foreach ($apis as $api => $etiqueta) : ?>
                    <td class="celda-api" data-api="<?= $e($api) ?>">
                        <label class="opcion">
                            <input type="checkbox" class="seleccion" name="sel[<?= $e($u['id_universo']) ?>][<?= $e($api) ?>]">
                            <span class="estado" data-estado="pendiente"></span>
                        </label>
                        <span class="ultima" title="Fecha de los datos actualmente almacenados"><?= $e($fecha($ultimas[$u['id_universo']][$api] ?? null)) ?></span>
                        <span class="detalle"></span>
                    </td>
                <?php endforeach; ?>
                <td class="accion-fila">
                    <button type="button" class="btn" data-sincronizar-fila="<?= $e($u['id_universo']) ?>" title="Sincronizar las tres partes de este universo">Sincronizar</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="acciones-finales">
        <span class="resumen" id="resumen"></span>
        <button type="button" class="btn-defecto" id="sincronizar-seleccion">Sincronizar seleccionados</button>
        <button type="button" class="btn-primario" id="sincronizar-todo">Sincronizar todo</button>
    </div>
</div>
<?php endif; ?>
<script src="/complementos/sincronizador_api/sincronizar.js"></script>
