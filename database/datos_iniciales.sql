-- =====================================================
-- SISTEMA CONTABLE
-- Datos iniciales para PostgreSQL
-- =====================================================

-- Impuestos
INSERT INTO impuestos (nombre, porcentaje, descripcion, estado)
VALUES
('IVA', 13.00, 'Impuesto al Valor Agregado', 'Activo')
ON CONFLICT DO NOTHING;


-- Métodos de pago
INSERT INTO metodos_pago (nombre, descripcion, estado)
VALUES
('Efectivo', 'Pago realizado en efectivo', 'Activo'),
('Tarjeta', 'Pago realizado con tarjeta', 'Activo'),
('Transferencia bancaria', 'Pago mediante transferencia bancaria', 'Activo')
ON CONFLICT (nombre) DO NOTHING;