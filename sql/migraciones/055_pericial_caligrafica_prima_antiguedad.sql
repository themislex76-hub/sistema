-- Datos adicionales para que las demandas de Rescisión Art. 51 y Prima de
-- Antigüedad salgan prácticamente listas, con el mínimo de texto libre:
--
-- 1) Prima de Antigüedad: restaura completo el bloque de documentales
--    privadas + pericial caligráfica/grafoscópica/grafométrica (estilo Mir
--    Castro), que antes se había simplificado. "inspeccion_extremos_texto"
--    (migración 054) ya NO se usa en Rescisión -- se sustituyó por extremos
--    fijos que se autocompletan solos, igual que en la demanda de despido
--    -- se deja esa columna sin usar, sin eliminarla, por si ya se corrió
--    esa migración.
--
-- 2) Rescisión Art. 51: "rescision_motivo_breve" y el texto libre de
--    "hecho_causa_narrativa" como ÚNICA fuente del hecho 4 se sustituyen por
--    dos campos cortos (fecha desde la que incumplió + persona responsable)
--    que arman solos un párrafo completo según la fracción del art. 51
--    elegida (ver FRACCION_51 en app.js); "hecho_causa_narrativa" pasa a ser
--    un párrafo adicional OPCIONAL para detalles extra, no obligatorio.
ALTER TABLE expedientes
  ADD COLUMN fecha_hoja_servicios DATE NULL,
  ADD COLUMN numero_documento_fump VARCHAR(50) NULL,
  ADD COLUMN fecha_documento_fump DATE NULL,
  ADD COLUMN fecha_desde_incumplimiento DATE NULL,
  ADD COLUMN persona_responsable_incumplimiento VARCHAR(255) NULL;
