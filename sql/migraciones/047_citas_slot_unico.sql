-- Bug real detectado en producción: dos clientes reservaron el mismo
-- horario (mismo abogado, misma fecha, misma hora) casi al mismo tiempo, y
-- aunque citas_crear_pendiente() ya usaba un candado (FOR UPDATE) sobre
-- disponibilidad_asesorias para evitar justo esto, hay una condición de
-- carrera posible: el candado bloquea esa tabla, pero la consulta que
-- revisa "¿ya está ocupado este horario?" es una lectura normal (no
-- bloqueante) sobre citas_asesoria -- bajo el nivel de aislamiento
-- REPEATABLE READ de MySQL, esa lectura puede no ver todavía la cita que
-- la otra transacción acaba de insertar, dejando pasar un doble-booking
-- real para el mismo abogado.
--
-- Esta columna generada + índice único lo hace imposible a nivel de base
-- de datos, sin importar qué tan ajustado sea el momento: solo puede
-- existir UNA cita 'confirmada' o 'pendiente_pago' por abogado/fecha/hora
-- a la vez. Las citas canceladas o expiradas no cuentan (slot_ocupado
-- queda NULL para ellas, y MySQL permite múltiples NULL en una columna
-- única), así que un horario libre sigue pudiéndose reutilizar
-- normalmente. citas_crear_pendiente() ya se actualizó para tratar un
-- choque contra este índice como "el horario ya no está disponible" en
-- vez de un error.
ALTER TABLE citas_asesoria
  ADD COLUMN slot_ocupado VARCHAR(80)
    GENERATED ALWAYS AS (
      CASE WHEN estado IN ('confirmada', 'pendiente_pago')
           THEN CONCAT(usuario_id, '|', fecha, '|', hora_inicio)
           ELSE NULL END
    ) STORED,
  ADD UNIQUE KEY uq_citas_slot_ocupado (slot_ocupado);
