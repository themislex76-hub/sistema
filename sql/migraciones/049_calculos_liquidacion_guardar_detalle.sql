-- Guarda el cálculo completo (JSON) y el salario diario usado, para poder
-- regenerar el PDF exacto cuando la persona compre el documento oficial
-- membretado más adelante en la conversación (o en otra conversación
-- después), sin depender de que siga activo el contexto del chat.
ALTER TABLE calculos_liquidacion
  ADD COLUMN calculo_json TEXT NULL AFTER monto_total,
  ADD COLUMN salario_diario DECIMAL(10,2) NULL AFTER calculo_json;
