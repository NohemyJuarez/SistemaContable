-- =====================================================
-- ROLES Y USUARIOS - SISTEMA CONTABLE
-- Ejecutar en el Query Tool de la base sistema_contable.
-- Se puede ejecutar más de una vez sin problema.
-- =====================================================

-- 1. Extensión para cifrar contraseñas (bcrypt) desde SQL
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- 2. La columna debe tener espacio para el hash (60 caracteres o más)
ALTER TABLE usuarios ALTER COLUMN contrasena TYPE VARCHAR(255);

-- 3. Cifrar cualquier contraseña que siga en texto plano
--    (por ejemplo, el usuario de prueba creado a mano; conserva la misma contraseña)
UPDATE usuarios
SET contrasena = crypt(contrasena, gen_salt('bf', 10))
WHERE contrasena NOT LIKE '$2%';

-- 4. Dejar solo los 4 roles válidos
--    Los usuarios con un rol anterior (por ejemplo 'Empleado') pasan a 'Vendedor'
ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS chk_rol;
ALTER TABLE usuarios ALTER COLUMN rol SET DEFAULT 'Vendedor';

UPDATE usuarios
SET rol = 'Vendedor'
WHERE rol NOT IN ('Administrador', 'Contador', 'Vendedor', 'Encargado de inventario');

ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_rol_check;

ALTER TABLE usuarios
ADD CONSTRAINT usuarios_rol_check
CHECK (rol IN ('Administrador', 'Contador', 'Vendedor', 'Encargado de inventario'));

-- 5. El nombre de usuario no se puede repetir
CREATE UNIQUE INDEX IF NOT EXISTS usuarios_usuario_unico ON usuarios (usuario);

-- 6. Un usuario de ejemplo por rol
INSERT INTO usuarios (nombre, usuario, correo, contrasena, rol)
VALUES
('Administrador del Sistema',   'admin',      'admin@sistemacontable.com',      crypt('Admin123*',      gen_salt('bf', 10)), 'Administrador'),
('Contador de Ejemplo',         'contador',   'contador@sistemacontable.com',   crypt('Contador123*',   gen_salt('bf', 10)), 'Contador'),
('Vendedor de Ejemplo',         'vendedor',   'vendedor@sistemacontable.com',   crypt('Vendedor123*',   gen_salt('bf', 10)), 'Vendedor'),
('Encargado de Inventario',     'inventario', 'inventario@sistemacontable.com', crypt('Inventario123*', gen_salt('bf', 10)), 'Encargado de inventario')
ON CONFLICT DO NOTHING;

-- Verificar:
-- SELECT id, nombre, usuario, rol, estado FROM usuarios ORDER BY id;