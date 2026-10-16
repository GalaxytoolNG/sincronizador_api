-- Último documento importado de cada parte del API por universo
CREATE TABLE IF NOT EXISTS c_sincronizador_api_partes (
    id_universo VARCHAR(10) NOT NULL,
    parte VARCHAR(16) NOT NULL,
    timestamp BIGINT NOT NULL,
    momento BIGINT NOT NULL,
    registros INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (id_universo, parte)
);
