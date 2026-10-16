-- Último documento importado de cada parte del API por universo
CREATE TABLE IF NOT EXISTS c_sincronizador_api_partes (
    id_universo TEXT NOT NULL,
    parte TEXT NOT NULL,
    timestamp INTEGER NOT NULL,
    momento INTEGER NOT NULL,
    registros INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (id_universo, parte)
);
