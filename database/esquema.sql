CREATE TABLE cuentas_contables (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo VARCHAR(20) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    naturaleza VARCHAR(10) NOT NULL,
    cuenta_padre_id INTEGER,
    permite_movimientos BOOLEAN NOT NULL DEFAULT TRUE,
    estado VARCHAR(10) NOT NULL DEFAULT 'Activa',

    CONSTRAINT fk_cuenta_padre
        FOREIGN KEY (cuenta_padre_id)
        REFERENCES cuentas_contables(id),

    CONSTRAINT chk_tipo
        CHECK (tipo IN (
            'Activo',
            'Pasivo',
            'Patrimonio',
            'Ingreso',
            'Costo',
            'Gasto'
        )),

    CONSTRAINT chk_naturaleza
        CHECK (naturaleza IN ('Deudora', 'Acreedora')),

    CONSTRAINT chk_estado
        CHECK (estado IN ('Activa', 'Inactiva'))
);


CREATE TABLE usuarios (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    correo VARCHAR(150) NOT NULL UNIQUE,
    contrasena VARCHAR(255) NOT NULL,
    rol VARCHAR(30) NOT NULL DEFAULT 'Empleado',
    estado VARCHAR(10) NOT NULL DEFAULT 'Activo',
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_rol
        CHECK (rol IN ('Administrador', 'Contador', 'Empleado')),

    CONSTRAINT chk_estado_usuario
        CHECK (estado IN ('Activo', 'Inactivo'))
);


CREATE TABLE clientes (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    dui VARCHAR(10) UNIQUE,
    nit VARCHAR(17) UNIQUE,
    telefono VARCHAR(20),
    correo VARCHAR(150),
    direccion TEXT,
    estado VARCHAR(10) NOT NULL DEFAULT 'Activo',
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_estado_cliente
        CHECK (estado IN ('Activo', 'Inactivo'))
);


CREATE TABLE proveedores (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    nit VARCHAR(17) UNIQUE,
    telefono VARCHAR(20),
    correo VARCHAR(150),
    direccion TEXT,
    estado VARCHAR(10) NOT NULL DEFAULT 'Activo',
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_estado_proveedor
        CHECK (estado IN ('Activo', 'Inactivo'))
);


CREATE TABLE productos (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL UNIQUE,
    nombre VARCHAR(150) NOT NULL,
    descripcion TEXT,
    tipo VARCHAR(15) NOT NULL DEFAULT 'Producto',
    precio_venta NUMERIC(12,2) NOT NULL DEFAULT 0,
    costo NUMERIC(12,2) NOT NULL DEFAULT 0,
    existencia INTEGER NOT NULL DEFAULT 0,
    estado VARCHAR(10) NOT NULL DEFAULT 'Activo',
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT chk_tipo_producto
        CHECK (tipo IN ('Producto', 'Servicio')),

    CONSTRAINT chk_precio_venta
        CHECK (precio_venta >= 0),

    CONSTRAINT chk_costo
        CHECK (costo >= 0),

    CONSTRAINT chk_existencia
        CHECK (existencia >= 0),

    CONSTRAINT chk_estado_producto
        CHECK (estado IN ('Activo', 'Inactivo'))
);


CREATE TABLE ventas (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    numero_venta VARCHAR(30) NOT NULL UNIQUE,
    cliente_id INTEGER NOT NULL,
    usuario_id INTEGER NOT NULL,
    fecha_venta TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    subtotal NUMERIC(12,2) NOT NULL DEFAULT 0,
    impuesto NUMERIC(12,2) NOT NULL DEFAULT 0,
    total NUMERIC(12,2) NOT NULL DEFAULT 0,
    estado VARCHAR(15) NOT NULL DEFAULT 'Pendiente',

    CONSTRAINT fk_venta_cliente
        FOREIGN KEY (cliente_id) REFERENCES clientes(id),

    CONSTRAINT fk_venta_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

    CONSTRAINT chk_estado_venta
        CHECK (estado IN ('Pendiente', 'Pagada', 'Anulada')),

    CONSTRAINT chk_total_venta
        CHECK (subtotal >= 0 AND impuesto >= 0 AND total >= 0)
);


CREATE TABLE detalle_ventas (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    venta_id INTEGER NOT NULL,
    producto_id INTEGER NOT NULL,
    cantidad INTEGER NOT NULL,
    precio_unitario NUMERIC(12,2) NOT NULL,
    descuento NUMERIC(12,2) NOT NULL DEFAULT 0,
    subtotal NUMERIC(12,2) NOT NULL,

    CONSTRAINT fk_detalle_venta
        FOREIGN KEY (venta_id) REFERENCES ventas(id),

    CONSTRAINT fk_detalle_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id),

    CONSTRAINT chk_cantidad_venta
        CHECK (cantidad > 0),

    CONSTRAINT chk_precio_unitario
        CHECK (precio_unitario >= 0),

    CONSTRAINT chk_descuento_venta
        CHECK (descuento >= 0),

    CONSTRAINT chk_subtotal_detalle
        CHECK (subtotal >= 0)
);


CREATE TABLE compras (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    numero_compra VARCHAR(30) NOT NULL UNIQUE,
    proveedor_id INTEGER NOT NULL,
    usuario_id INTEGER NOT NULL,
    fecha_compra TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    subtotal NUMERIC(12,2) NOT NULL DEFAULT 0,
    impuesto NUMERIC(12,2) NOT NULL DEFAULT 0,
    total NUMERIC(12,2) NOT NULL DEFAULT 0,
    estado VARCHAR(15) NOT NULL DEFAULT 'Pendiente',

    CONSTRAINT fk_compra_proveedor
        FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),

    CONSTRAINT fk_compra_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

    CONSTRAINT chk_estado_compra
        CHECK (estado IN ('Pendiente', 'Pagada', 'Anulada')),

    CONSTRAINT chk_total_compra
        CHECK (subtotal >= 0 AND impuesto >= 0 AND total >= 0)
);


CREATE TABLE detalle_compras (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    compra_id INTEGER NOT NULL,
    producto_id INTEGER NOT NULL,
    cantidad INTEGER NOT NULL,
    costo_unitario NUMERIC(12,2) NOT NULL,
    subtotal NUMERIC(12,2) NOT NULL,

    CONSTRAINT fk_detalle_compra
        FOREIGN KEY (compra_id) REFERENCES compras(id),

    CONSTRAINT fk_detalle_producto_compra
        FOREIGN KEY (producto_id) REFERENCES productos(id),

    CONSTRAINT chk_cantidad_compra
        CHECK (cantidad > 0),

    CONSTRAINT chk_costo_unitario
        CHECK (costo_unitario >= 0),

    CONSTRAINT chk_subtotal_compra
        CHECK (subtotal >= 0)
);


CREATE TABLE partidas_diario (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    numero_partida VARCHAR(30) NOT NULL UNIQUE,
    fecha DATE NOT NULL DEFAULT CURRENT_DATE,
    descripcion TEXT NOT NULL,
    tipo_origen VARCHAR(20) NOT NULL DEFAULT 'Manual',
    estado VARCHAR(15) NOT NULL DEFAULT 'Borrador',
    usuario_id INTEGER NOT NULL,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_partida_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

    CONSTRAINT chk_tipo_origen
        CHECK (tipo_origen IN ('Manual', 'Venta', 'Compra')),

    CONSTRAINT chk_estado_partida
        CHECK (estado IN ('Borrador', 'Contabilizada', 'Anulada'))
);


CREATE TABLE movimientos_diario (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    partida_id INTEGER NOT NULL,
    cuenta_id INTEGER NOT NULL,
    descripcion TEXT,
    debe NUMERIC(12,2) NOT NULL DEFAULT 0,
    haber NUMERIC(12,2) NOT NULL DEFAULT 0,

    CONSTRAINT fk_movimiento_partida
        FOREIGN KEY (partida_id) REFERENCES partidas_diario(id),

    CONSTRAINT fk_movimiento_cuenta
        FOREIGN KEY (cuenta_id) REFERENCES cuentas_contables(id),

    CONSTRAINT chk_debe_haber
        CHECK (
            (debe > 0 AND haber = 0)
            OR (haber > 0 AND debe = 0)
        )
);


CREATE TABLE metodos_pago (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(50) NOT NULL UNIQUE,
    descripcion TEXT,
    estado VARCHAR(10) NOT NULL DEFAULT 'Activo',

    CONSTRAINT chk_estado_metodo_pago
        CHECK (estado IN ('Activo', 'Inactivo'))
);


CREATE TABLE pagos (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    venta_id INTEGER NOT NULL,
    metodo_pago_id INTEGER NOT NULL,
    monto NUMERIC(12,2) NOT NULL,
    fecha_pago TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    referencia VARCHAR(100),

    CONSTRAINT fk_pago_venta
        FOREIGN KEY (venta_id) REFERENCES ventas(id),

    CONSTRAINT fk_pago_metodo
        FOREIGN KEY (metodo_pago_id) REFERENCES metodos_pago(id),

    CONSTRAINT chk_monto_pago
        CHECK (monto > 0)
);


CREATE TABLE comprobantes (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    venta_id INTEGER NOT NULL,
    tipo_comprobante VARCHAR(50) NOT NULL,
    numero_comprobante VARCHAR(50) NOT NULL UNIQUE,
    fecha_emision TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado VARCHAR(15) NOT NULL DEFAULT 'Emitido',

    CONSTRAINT fk_comprobante_venta
        FOREIGN KEY (venta_id) REFERENCES ventas(id),

    CONSTRAINT chk_estado_comprobante
        CHECK (estado IN ('Emitido', 'Anulado'))
);


CREATE TABLE movimientos_inventario (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    producto_id INTEGER NOT NULL,
    tipo_movimiento VARCHAR(10) NOT NULL,
    cantidad INTEGER NOT NULL,
    descripcion TEXT,
    fecha_movimiento TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id INTEGER NOT NULL,

    CONSTRAINT fk_movimiento_producto
        FOREIGN KEY (producto_id) REFERENCES productos(id),

    CONSTRAINT fk_movimiento_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

    CONSTRAINT chk_tipo_movimiento
        CHECK (tipo_movimiento IN ('Entrada', 'Salida')),

    CONSTRAINT chk_cantidad_movimiento
        CHECK (cantidad > 0)
);


CREATE TABLE impuestos (
    id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    porcentaje NUMERIC(5,2) NOT NULL,
    descripcion TEXT,
    estado VARCHAR(10) NOT NULL DEFAULT 'Activo',

    CONSTRAINT chk_porcentaje_impuesto
        CHECK (porcentaje >= 0 AND porcentaje <= 100),

    CONSTRAINT chk_estado_impuesto
        CHECK (estado IN ('Activo', 'Inactivo'))
);


ALTER TABLE detalle_ventas
ADD COLUMN impuesto_id INTEGER;

ALTER TABLE detalle_ventas
ADD CONSTRAINT fk_detalle_venta_impuesto
FOREIGN KEY (impuesto_id) REFERENCES impuestos(id);


ALTER TABLE detalle_compras
ADD COLUMN impuesto_id INTEGER;

ALTER TABLE detalle_compras
ADD CONSTRAINT fk_detalle_compra_impuesto
FOREIGN KEY (impuesto_id) REFERENCES impuestos(id);


ALTER TABLE detalle_ventas
ADD COLUMN porcentaje_impuesto NUMERIC(5,2) NOT NULL DEFAULT 0;

ALTER TABLE detalle_compras
ADD COLUMN porcentaje_impuesto NUMERIC(5,2) NOT NULL DEFAULT 0;